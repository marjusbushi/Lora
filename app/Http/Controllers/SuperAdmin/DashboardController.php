<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\TenantBillingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(TenantBillingService $billing): Response
    {
        $tenants = Tenant::query()
            ->with(['subscription', 'moduleEntitlements', 'domains'])
            ->withCount('users')
            ->latest()
            ->get();

        $rows = $tenants->map(function (Tenant $tenant) use ($billing) {
            $summary = $billing->summary($tenant);
            $activeSubscription = in_array($summary['status'], ['active', 'trialing'], true);
            $mrrCents = ! $activeSubscription
                ? 0
                : ($summary['billing_cycle'] === 'annual'
                    ? (int) round($summary['annual_cents'] / 12)
                    : $summary['monthly_fixed_cents']);

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'subscription_status' => $summary['status'],
                'billing_cycle' => $summary['billing_cycle'],
                'mrr_cents' => $mrrCents,
                'users_count' => $tenant->users_count,
                'domain' => $tenant->domains->firstWhere('is_primary', true)?->domain
                    ?? $tenant->domains->first()?->domain,
                'created_at' => $tenant->created_at?->toIso8601String(),
                'modules' => collect($summary['modules'])
                    ->filter(fn (array $module) => $module['enabled'])
                    ->keys()
                    ->values(),
            ];
        });

        $catalog = $billing->catalog();
        $moduleAdoption = collect($catalog)->map(function (array $module, string $code) use ($rows) {
            $count = $rows->filter(fn (array $tenant) => $tenant['modules']->contains($code))->count();

            return [
                'code' => $code,
                'name' => $module['name'],
                'hotels_count' => $count,
            ];
        })->values();

        return Inertia::render('SuperAdmin/Dashboard', [
            'stats' => [
                'hotels_total' => $rows->count(),
                'hotels_active' => $rows->where('status', 'active')->count(),
                'subscriptions_active' => $rows->whereIn('subscription_status', ['active', 'trialing'])->count(),
                'subscriptions_attention' => $rows->whereIn('subscription_status', ['past_due', 'suspended'])->count(),
                'mrr_cents' => $rows->sum('mrr_cents'),
                'users_total' => $rows->sum('users_count'),
            ],
            'moduleAdoption' => $moduleAdoption,
            'recentTenants' => $rows->take(5)->values(),
        ]);
    }
    /**
     * Platform-wide activity feed for the control plane. AuditLog is a
     * TenantModel, but the control panel is TENANTLESS by design (context is
     * null here), so we read across ALL tenants with withoutGlobalScopes()
     * and join the owning tenant + causer explicitly — never relying on the
     * scope being off. Only platform-level (tenant.*) actions are shown.
     */
    public function activity(Request $request): Response
    {
        $action = (string) $request->query('action', '');

        $logs = AuditLog::withoutGlobalScopes()
            ->where('action', 'like', 'tenant.%')
            ->when($action !== '', fn ($query) => $query->where('action', $action))
            ->with(['causer:id,name,email', 'tenant:id,name,slug'])
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'created_at' => $log->created_at?->toIso8601String(),
                'ip_address' => $log->ip_address,
                'actor' => $log->causer?->name ?? 'Sistemi',
                'actor_email' => $log->causer?->email,
                'tenant' => $log->tenant?->name,
                // Compact, secret-free summary — properties only ever hold field
                // NAMES / booleans / non-secret config for platform actions.
                'summary' => $this->summarizeAudit($log),
            ]);

        $actions = AuditLog::withoutGlobalScopes()
            ->where('action', 'like', 'tenant.%')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return Inertia::render('SuperAdmin/Activity', [
            'logs' => $logs,
            'actions' => $actions,
            'filter' => ['action' => $action],
        ]);
    }

    private function summarizeAudit(AuditLog $log): ?string
    {
        $props = $log->properties ?? [];

        return match ($log->action) {
            'tenant.create' => $props['tenant_name'] ?? null,
            'tenant.switch' => 'Hyri në panelin e hotelit',
            'tenant.subscription.update' => 'Abonimi u përditësua',
            'tenant.integration.update' => trim(
                ucfirst((string) ($props['provider'] ?? 'integrim'))
                .(isset($props['enabled']) ? ($props['enabled'] ? ' · aktiv' : ' · joaktiv') : '')
                .(! empty($props['updated_fields']) ? ' · '.implode(', ', (array) $props['updated_fields']) : ''),
            ),
            'tenant.domain.create' => 'Shtoi '.($props['domain'] ?? 'domain'),
            'tenant.domain.delete' => 'Hoqi '.($props['domain'] ?? 'domain'),
            'tenant.domain.primary' => ($props['domain'] ?? 'domain').' u bë primar',
            'tenant.status' => 'Statusi → '.($props['status'] ?? '?'),
            default => null,
        };
    }
}
