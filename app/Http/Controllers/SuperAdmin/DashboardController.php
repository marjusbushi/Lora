<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantBillingService;
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
}
