<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSubscription;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class TenantBillingService
{
    public const CORE = 'core';

    public const CHANNEL_MANAGER = 'channel_manager';

    public const BOOKING_ENGINE = 'booking_engine';

    public const HOUSEKEEPING = 'housekeeping';

    public const POS = 'pos';

    public const FINANCE = 'finance';

    public const SMART_PRICING = 'smart_pricing';

    public function catalog(): array
    {
        return config('lora_modules.modules', []);
    }

    public function enabled(string $module, ?Tenant $tenant): bool
    {
        if (! $tenant || ! array_key_exists($module, $this->catalog())) {
            return false;
        }

        $snapshot = $this->accessSnapshot($tenant);

        return in_array($snapshot['status'], ['active', 'trialing'], true)
            && ($snapshot['modules'][$module] ?? false) === true;
    }

    public function accessSnapshot(Tenant $tenant): array
    {
        $snapshot = $tenant->metadata['billing_access'] ?? null;

        if (! is_array($snapshot)) {
            return [
                'status' => 'inactive',
                'billing_cycle' => 'monthly',
                'current_period_ends_at' => null,
                'modules' => array_fill_keys(array_keys($this->catalog()), false),
            ];
        }

        return [
            'status' => (string) ($snapshot['status'] ?? 'inactive'),
            'billing_cycle' => (string) ($snapshot['billing_cycle'] ?? 'monthly'),
            'current_period_ends_at' => $snapshot['current_period_ends_at'] ?? null,
            'modules' => array_replace(
                array_fill_keys(array_keys($this->catalog()), false),
                is_array($snapshot['modules'] ?? null) ? $snapshot['modules'] : [],
            ),
        ];
    }

    public function provision(Tenant $tenant, bool $enableAll = false): TenantSubscription
    {
        return DB::transaction(function () use ($tenant, $enableAll) {
            $subscription = $tenant->subscription()->firstOrCreate([], [
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'currency' => $tenant->currency,
                'annual_discount_percent' => (int) config('lora_modules.annual_discount_percent', 20),
                'starts_at' => now(),
            ]);

            foreach ($this->catalog() as $code => $module) {
                $tenant->moduleEntitlements()->firstOrCreate(
                    ['module_code' => $code],
                    [
                        'enabled' => $enableAll || $code === self::CORE,
                        'quantity' => $code === self::CHANNEL_MANAGER
                            ? max(1, (int) DB::table('rooms')->where('tenant_id', $tenant->id)->count())
                            : 1,
                        'unit_price_cents' => $module['unit_price_cents'] ?? null,
                        'percentage_bps' => $module['percentage_bps'] ?? null,
                        'pricing_snapshot' => $module,
                    ],
                );
            }

            $this->syncAccessSnapshot($tenant, $subscription);

            return $subscription;
        });
    }

    public function update(Tenant $tenant, array $data): void
    {
        DB::transaction(function () use ($tenant, $data) {
            $subscription = $tenant->subscription()->firstOrNew();
            $subscription->fill([
                'status' => $data['status'],
                'billing_cycle' => $data['billing_cycle'],
                'currency' => $tenant->currency,
                'starts_at' => $subscription->starts_at ?? now(),
                'current_period_ends_at' => $data['current_period_ends_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $subscription->save();

            foreach ($this->catalog() as $code => $module) {
                $input = Arr::get($data, "modules.{$code}", []);
                $enabled = $code === self::CORE || (bool) ($input['enabled'] ?? false);
                $quantity = max(1, (int) ($input['quantity'] ?? 1));

                $tenant->moduleEntitlements()->updateOrCreate(
                    ['module_code' => $code],
                    [
                        'enabled' => $enabled,
                        'quantity' => $quantity,
                        'unit_price_cents' => $module['unit_price_cents'] ?? null,
                        'percentage_bps' => $module['percentage_bps'] ?? null,
                        'pricing_snapshot' => $module,
                    ],
                );
            }

            $this->syncAccessSnapshot($tenant, $subscription);
        });
    }

    public function summary(Tenant $tenant): array
    {
        $tenant->loadMissing(['subscription', 'moduleEntitlements']);
        $subscription = $tenant->subscription;
        $entitlements = $tenant->moduleEntitlements->keyBy('module_code');
        $subscriptionAllowsAccess = $subscription
            && in_array($subscription->status, ['active', 'trialing'], true);
        $monthlyFixedCents = 0;
        $modules = [];

        foreach ($this->catalog() as $code => $definition) {
            /** @var TenantModuleEntitlement|null $entitlement */
            $entitlement = $entitlements->get($code);
            $pricing = array_replace($definition, $entitlement?->pricing_snapshot ?? []);
            $enabled = (bool) $entitlement?->enabled;
            $quantity = max(1, (int) ($entitlement?->quantity ?? 1));
            $monthlyCents = $enabled ? $this->monthlyPrice($pricing, $quantity) : 0;
            $monthlyFixedCents += $monthlyCents;

            $modules[$code] = [
                'code' => $code,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'billing_model' => $pricing['billing_model'],
                'unit_label' => $pricing['unit_label'],
                'enabled' => $enabled,
                'accessible' => $subscriptionAllowsAccess && $enabled,
                'quantity' => $quantity,
                'unit_price_cents' => $pricing['unit_price_cents'] ?? null,
                'percentage_bps' => $pricing['percentage_bps'] ?? null,
                'monthly_cents' => $monthlyCents,
                'locked' => $code === self::CORE,
            ];
        }

        $discount = (int) ($subscription?->annual_discount_percent
            ?? config('lora_modules.annual_discount_percent', 20));
        $annualCents = (int) round($monthlyFixedCents * 12 * ((100 - $discount) / 100));

        return [
            'status' => $subscription?->status ?? 'inactive',
            'billing_cycle' => $subscription?->billing_cycle ?? 'monthly',
            'currency' => $subscription?->currency ?? $tenant->currency,
            'current_period_ends_at' => $subscription?->current_period_ends_at?->toDateString(),
            'notes' => $subscription?->notes,
            'monthly_fixed_cents' => $monthlyFixedCents,
            'annual_cents' => $annualCents,
            'annual_discount_percent' => $discount,
            'modules' => $modules,
        ];
    }

    private function monthlyPrice(array $module, int $quantity): int
    {
        return match ($module['billing_model']) {
            'flat' => (int) ($module['unit_price_cents'] ?? 0),
            'per_user', 'per_pos' => $quantity * (int) ($module['unit_price_cents'] ?? 0),
            'tiered_per_room' => $this->tieredRoomPrice($module, $quantity),
            default => 0,
        };
    }

    private function tieredRoomPrice(array $module, int $quantity): int
    {
        $limit = (int) ($module['tier_limit'] ?? 50);
        $standard = (int) ($module['unit_price_cents'] ?? 0);
        $excess = (int) ($module['excess_unit_price_cents'] ?? $standard);

        return min($quantity, $limit) * $standard + max(0, $quantity - $limit) * $excess;
    }

    private function syncAccessSnapshot(Tenant $tenant, TenantSubscription $subscription): void
    {
        $moduleAccess = $tenant->moduleEntitlements()
            ->pluck('enabled', 'module_code')
            ->map(fn ($enabled) => (bool) $enabled)
            ->all();
        $metadata = $tenant->metadata ?? [];
        $metadata['billing_access'] = [
            'status' => $subscription->status,
            'billing_cycle' => $subscription->billing_cycle,
            'current_period_ends_at' => $subscription->current_period_ends_at?->toDateString(),
            'modules' => array_replace(
                array_fill_keys(array_keys($this->catalog()), false),
                $moduleAccess,
            ),
        ];

        $tenant->forceFill(['metadata' => $metadata])->saveQuietly();
    }
}
