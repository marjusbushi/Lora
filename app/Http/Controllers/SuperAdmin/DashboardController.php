<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\TenantBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(TenantBillingService $billing): Response
    {
        $tenants = Tenant::query()
            ->with(['subscription', 'moduleEntitlements', 'domains', 'integrations'])
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
            $enabledModules = collect($summary['modules'])
                ->filter(fn (array $module) => $module['enabled'])
                ->keys()
                ->values();
            $requiredProviders = collect([
                'channex' => $enabledModules->contains('channel_manager'),
                'pok' => $enabledModules->contains('booking_engine'),
            ])->filter()->keys();
            $readyProviders = $requiredProviders->filter(function (string $provider) use ($tenant) {
                $integration = $tenant->integrations->firstWhere('provider', $provider);
                if (! $integration?->enabled) {
                    return false;
                }

                $credentials = $integration->credentials ?? [];
                $configuration = $integration->configuration ?? [];

                return match ($provider) {
                    'channex' => filled($credentials['api_key'] ?? null)
                        && filled($configuration['property_id'] ?? null),
                    'pok' => filled($credentials['key_id'] ?? null)
                        && filled($credentials['key_secret'] ?? null)
                        && filled($configuration['merchant_id'] ?? null),
                    default => true,
                };
            });

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
                'integrations_ready' => $readyProviders->count(),
                'integrations_total' => $requiredProviders->count(),
                'created_at' => $tenant->created_at?->toIso8601String(),
                'period_ends_at' => $summary['current_period_ends_at'] ?? null,
                'modules' => $enabledModules,
            ];
        });

        // Operator's daily driver: hotels that need a human — payment lapsed,
        // suspended, or a subscription/trial ending within 14 days.
        $soon = now()->addDays(14);
        $needsAttention = $rows
            ->map(function (array $row) use ($soon) {
                $reason = null;
                $severity = 'info';
                if ($row['status'] === 'suspended') {
                    $reason = 'Hoteli është i pezulluar';
                    $severity = 'danger';
                } elseif ($row['subscription_status'] === 'past_due') {
                    $reason = 'Pagesë e vonuar';
                    $severity = 'danger';
                } elseif ($row['period_ends_at']
                    && Carbon::parse($row['period_ends_at'])->lte($soon)) {
                    $reason = $row['subscription_status'] === 'trialing'
                        ? 'Prova mbaron së shpejti'
                        : 'Abonimi rinovohet së shpejti';
                    $severity = 'warning';
                }

                return $reason ? [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'reason' => $reason,
                    'severity' => $severity,
                    'date' => $row['period_ends_at'],
                ] : null;
            })
            ->filter()
            ->sortBy(fn (array $r) => ['danger' => 0, 'warning' => 1, 'info' => 2][$r['severity']] ?? 3)
            ->values();

        $catalog = $billing->catalog();
        $moduleAdoption = collect($catalog)->map(function (array $module, string $code) use ($rows) {
            $count = $rows->filter(fn (array $tenant) => $tenant['modules']->contains($code))->count();

            return [
                'code' => $code,
                'name' => $module['name'],
                'hotels_count' => $count,
            ];
        })->values();

        $hotelsTotal = $rows->count();
        $hotelsActive = $rows->where('status', 'active')->count();
        $subscriptionsActive = $rows->whereIn('subscription_status', ['active', 'trialing'])->count();
        $domainsConfigured = $rows->filter(fn (array $row) => filled($row['domain']))->count();
        $integrationsReady = $rows->sum('integrations_ready');
        $integrationsTotal = $rows->sum('integrations_total');
        $ratio = static fn (int $value, int $total): float => $total > 0 ? $value / $total : 1;
        $healthScore = (int) round(
            ($ratio($hotelsActive, $hotelsTotal) * 35)
            + ($ratio($subscriptionsActive, $hotelsTotal) * 35)
            + ($ratio($domainsConfigured, $hotelsTotal) * 15)
            + ($ratio($integrationsReady, $integrationsTotal) * 15)
        );

        return Inertia::render('SuperAdmin/Dashboard', [
            'stats' => [
                'hotels_total' => $hotelsTotal,
                'hotels_active' => $hotelsActive,
                'subscriptions_active' => $subscriptionsActive,
                'subscriptions_attention' => $rows->whereIn('subscription_status', ['past_due', 'suspended'])->count(),
                'mrr_cents' => $rows->sum('mrr_cents'),
                'users_total' => $rows->sum('users_count'),
                'domains_configured' => $domainsConfigured,
                'integrations_ready' => $integrationsReady,
                'integrations_total' => $integrationsTotal,
                'health_score' => $healthScore,
            ],
            'moduleAdoption' => $moduleAdoption,
            'needsAttention' => $needsAttention,
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
        $search = trim((string) $request->query('q', ''));
        $tenantId = $request->integer('tenant') ?: null;
        $range = in_array((string) $request->query('range', '7'), ['today', '7', '30'], true)
            ? (string) $request->query('range', '7')
            : '7';
        $rangeStart = match ($range) {
            'today' => now()->startOfDay(),
            '30' => now()->subDays(30),
            default => now()->subDays(7),
        };

        $logsQuery = AuditLog::withoutGlobalScopes()
            ->where('action', 'like', 'tenant.%')
            ->when($action !== '', fn ($query) => $query->where('action', $action))
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('created_at', '>=', $rangeStart)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like) {
                    $query->where('ip_address', 'like', $like)
                        ->orWhereHas('causer', fn ($query) => $query
                            ->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like))
                        ->orWhereHas('tenant', fn ($query) => $query
                            ->where('name', 'like', $like)
                            ->orWhere('slug', 'like', $like));
                });
            });

        $logs = $logsQuery
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

        $lastDay = AuditLog::withoutGlobalScopes()
            ->where('action', 'like', 'tenant.%')
            ->where('created_at', '>=', now()->subDay());

        $actions = AuditLog::withoutGlobalScopes()
            ->where('action', 'like', 'tenant.%')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return Inertia::render('SuperAdmin/Activity', [
            'logs' => $logs,
            'actions' => $actions,
            'hotels' => Tenant::query()->orderBy('name')->get(['id', 'name']),
            'stats' => [
                'actions_24h' => (clone $lastDay)->count(),
                'hotels_24h' => (clone $lastDay)->whereNotNull('tenant_id')->distinct()->count('tenant_id'),
                'admins_24h' => (clone $lastDay)->whereNotNull('causer_id')->distinct()->count('causer_id'),
            ],
            'filter' => [
                'action' => $action,
                'q' => $search,
                'tenant' => $tenantId,
                'range' => $range,
            ],
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
