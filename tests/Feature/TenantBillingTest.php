<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\ChannexConfiguration;
use App\Services\TenantBillingService;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_hotel_keeps_every_module_enabled_after_billing_migration(): void
    {
        $tenant = Tenant::query()->sole();
        $billing = app(TenantBillingService::class)->summary($tenant);

        $this->assertSame('active', $billing['status']);
        $this->assertSame('monthly', $billing['billing_cycle']);
        // core 2900 + channel_manager 700 (1 dhomë) + housekeeping 900 + pos 4900 (pika e parë)
        // + finance 2900 + smart_pricing 4900 + beach 2900 = 20100.
        $this->assertSame(20100, $billing['monthly_fixed_cents']);
        // Pa override: shkalla e kontratës, default 1 vit → 10%: 20100×12×0.9.
        $this->assertSame(1, $billing['contract_years']);
        $this->assertSame(10, $billing['annual_discount_percent']);
        $this->assertSame(217080, $billing['annual_cents']);
        $this->assertSame(
            [1 => 10, 2 => 15, 3 => 20, 5 => 30],
            collect($billing['contract_options'])->pluck('discount_percent', 'years')->all(),
        );
        $this->assertSame(
            array_keys(config('lora_modules.modules')),
            array_keys(array_filter($billing['modules'], fn (array $module) => $module['enabled'])),
        );
    }

    public function test_existing_hotel_roles_receive_finance_permissions(): void
    {
        $tenant = Tenant::query()->sole();

        app(TenantContext::class)->run($tenant, function () use ($tenant) {
            $admin = Role::query()->where('team_id', $tenant->id)->where('name', 'admin')->firstOrFail();
            $manager = Role::query()->where('team_id', $tenant->id)->where('name', 'manager')->firstOrFail();
            $receptionist = Role::query()->where('team_id', $tenant->id)->where('name', 'receptionist')->firstOrFail();

            $this->assertTrue($admin->hasPermissionTo('manage_finance_settings'));
            $this->assertTrue($manager->hasPermissionTo('manage_bills'));
            // Renato (2026-08-18): the desk lost the whole Financa module;
            // create_payment remains as the folio-checkout marker.
            $this->assertFalse($receptionist->hasPermissionTo('view_finance'));
            $this->assertTrue($receptionist->hasPermissionTo('create_payment'));
            $this->assertFalse($receptionist->hasPermissionTo('view_bank_accounts'));
        });
    }

    public function test_new_hotel_starts_with_core_only_and_disabled_module_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantBillingService::class)->provision($tenant);
        app(TenantRoleService::class)->provision($tenant);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'new-hotel.test',
            'is_primary' => true,
        ]);
        app(TenantContext::class)->set($tenant);

        $admin = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $tenant->users()->syncWithoutDetaching([
            $admin->id => ['is_owner' => true, 'is_active' => true],
        ]);
        $admin->assignRole('admin');

        app(TenantContext::class)->clear();

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('https://new-hotel.test'.route('housekeeping.index', absolute: false))
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('https://new-hotel.test'.route('inventory.index', absolute: false))
            ->assertForbidden();

        $billing = app(TenantBillingService::class)->summary($tenant->fresh());
        $this->assertTrue($billing['modules']['core']['enabled']);
        $this->assertFalse($billing['modules']['housekeeping']['enabled']);
        $this->assertSame(2900, $billing['monthly_fixed_cents']);
    }

    public function test_super_admin_can_configure_modules_quantities_and_annual_discount(): void
    {
        config(['lora.control_panel_hosts' => ['localhost']]);

        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $tenant->id,
        ]);

        $payload = [
            'status' => 'active',
            'billing_cycle' => 'annual',
            'contract_years' => 3,
            'current_period_ends_at' => '2027-07-11',
            'notes' => 'Kontratë vjetore test.',
            'modules' => [
                'core' => ['enabled' => false, 'quantity' => 1],
                'channel_manager' => ['enabled' => true, 'quantity' => 60],
                'messages' => ['enabled' => false, 'quantity' => 1],
                'booking_engine' => ['enabled' => true, 'quantity' => 1],
                'maintenance' => ['enabled' => false, 'quantity' => 1],
                'housekeeping' => ['enabled' => true, 'quantity' => 2],
                'pos' => ['enabled' => true, 'quantity' => 3],
                'finance' => ['enabled' => true, 'quantity' => 1],
                'smart_pricing' => ['enabled' => true, 'quantity' => 1],
                'beach' => ['enabled' => true, 'quantity' => 1],
            ],
        ];

        app(TenantContext::class)->clear();

        $this->actingAs($superAdmin)
            ->withSession(['tenant_id' => $tenant->id])
            ->put(route('super-admin.tenants.subscription.update', $tenant), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $summary = app(TenantBillingService::class)->summary($tenant->fresh());

        $this->assertTrue($summary['modules']['core']['enabled'], 'Core must stay enabled.');
        $this->assertSame(60, $summary['modules']['channel_manager']['quantity']);
        // core 2900 + cm 60 dhoma (30×700 + 30×500 = 36000) + hk 2×900 + pos 3 pika
        // (4900 + 2×1900 = 8700) + finance 2900 + smart 4900 + beach 2900 = 60100.
        $this->assertSame(60100, $summary['monthly_fixed_cents']);
        // Kontrata 3-vjeçare → 20%: 60100×12×0.8.
        $this->assertSame(3, $summary['contract_years']);
        $this->assertSame(20, $summary['annual_discount_percent']);
        $this->assertSame(576960, $summary['annual_cents']);
        $this->assertSame(100, $summary['modules']['booking_engine']['percentage_bps']);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'tenant.subscription.update',
        ]);
    }

    public function test_disabled_channel_manager_cannot_use_tenant_channex_credentials(): void
    {
        config(['services.channex.testing_legacy_fallback' => false]);

        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        TenantIntegration::updateOrCreate(
            ['provider' => 'channex'],
            [
                'enabled' => true,
                'credentials' => ['api_key' => 'secret', 'webhook_secret' => 'webhook'],
                'configuration' => [
                    'property_id' => 'property-id',
                    'base_url' => 'https://app.channex.io/api/v1',
                ],
            ],
        );

        $billing = app(TenantBillingService::class);
        $payload = $this->billingPayload($billing->summary($tenant));
        $payload['modules'][TenantBillingService::CHANNEL_MANAGER]['enabled'] = false;
        $billing->update($tenant, $payload);

        $this->assertFalse(app(ChannexConfiguration::class)->configured());

        $payload['modules'][TenantBillingService::CHANNEL_MANAGER]['enabled'] = true;
        $billing->update($tenant->fresh(), $payload);
        app(TenantContext::class)->set($tenant->fresh());

        $this->assertTrue(app(ChannexConfiguration::class)->configured());
    }

    public function test_contract_ladder_discounts_and_negotiated_override(): void
    {
        $tenant = Tenant::query()->sole();
        $subscription = $tenant->subscription;

        // Çdo shkallë e kontratës jep zbritjen e vet mbi totalin vjetor.
        foreach ([1 => 10, 2 => 15, 3 => 20, 5 => 30] as $years => $percent) {
            $subscription->update(['contract_years' => $years]);
            $summary = app(TenantBillingService::class)->summary($tenant->fresh());
            $this->assertSame($percent, $summary['annual_discount_percent'], "Kontrata {$years}-vjeçare");
            $this->assertSame(
                (int) round($summary['monthly_fixed_cents'] * 12 * ((100 - $percent) / 100)),
                $summary['annual_cents'],
            );
        }

        // Negociata e veçantë (override) fiton mbi shkallën.
        $subscription->update(['contract_years' => 1, 'discount_override_percent' => 25]);
        $summary = app(TenantBillingService::class)->summary($tenant->fresh());
        $this->assertSame(25, $summary['annual_discount_percent']);
        $this->assertSame(25, $summary['discount_override_percent']);
    }

    public function test_snapshot_backfill_migration_moves_old_frozen_prices_to_the_new_catalog(): void
    {
        $tenant = Tenant::query()->sole();

        // Simulo një tenant EKZISTUES: snapshot i ngrirë me çmimin e vjetër të plazhit
        // (1900c) — summary e respekton snapshot-in dhe faturon çmimin e vjetër.
        $tenant->moduleEntitlements()->where('module_code', 'beach')->update([
            'pricing_snapshot' => json_encode(array_replace(
                config('lora_modules.modules.beach'),
                ['unit_price_cents' => 1900],
            )),
            'unit_price_cents' => 1900,
        ]);
        $summary = app(TenantBillingService::class)->summary($tenant->fresh());
        $this->assertSame(1900, $summary['modules']['beach']['monthly_cents']);

        // Backfill-i i migrimit i sjell të gjithë te katalogu i ri — pa ri-ruajtje dorazi.
        $migration = require database_path('migrations/2026_08_14_210000_backfill_entitlement_pricing_snapshots.php');

        // Rezerva nga ekzekutimi i parë (RefreshDatabase) fshihet që up() të
        // fotografojë vlerat e vjetra që sapo simuluam — si në një server real.
        $migration->down();
        // down() riktheu vlerat e para të rezervës — rivendos simulimin e vjetër.
        $tenant->moduleEntitlements()->where('module_code', 'beach')->update([
            'pricing_snapshot' => json_encode(array_replace(
                config('lora_modules.modules.beach'),
                ['unit_price_cents' => 1900],
            )),
            'unit_price_cents' => 1900,
        ]);
        $migration->up();

        $tenant = Tenant::query()->sole()->fresh();
        $tenant->unsetRelation('moduleEntitlements');
        $summary = app(TenantBillingService::class)->summary($tenant);
        $this->assertSame(2900, $summary['modules']['beach']['monthly_cents']);

        // Rollback-u EKZAKT (gate-i mysql-upgrade): down() rikthen vlerat e vjetra
        // rresht për rresht dhe heq tabelën e rezervës.
        $migration->down();
        $tenant = Tenant::query()->sole()->fresh();
        $tenant->unsetRelation('moduleEntitlements');
        $summary = app(TenantBillingService::class)->summary($tenant);
        $this->assertSame(1900, $summary['modules']['beach']['monthly_cents']);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('entitlement_pricing_backup_20260814'));

        // Ri-aplikimi përfundimtar — gjendja e synuar pas deploy-it.
        $migration->up();
    }

    public function test_billing_update_without_contract_years_keeps_the_existing_value(): void
    {
        config(['lora.control_panel_hosts' => ['localhost']]);
        $tenant = Tenant::query()->sole();
        $tenant->subscription->update(['contract_years' => 3]);
        $superAdmin = User::factory()->create(['is_super_admin' => true, 'current_tenant_id' => $tenant->id]);

        // Sirtari i listës së tenant-ëve s'e dërgon contract_years — s'duhet të thyhet
        // dhe vlera ekzistuese e kontratës duhet të mbetet.
        $summary = app(TenantBillingService::class)->summary($tenant);
        $this->actingAs($superAdmin)
            ->withSession(['tenant_id' => $tenant->id])
            ->put(route('super-admin.tenants.subscription.update', $tenant), $this->billingPayload($summary))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $tenant->fresh()->subscription->contract_years);
    }

    private function billingPayload(array $summary): array
    {
        return [
            'status' => $summary['status'],
            'billing_cycle' => $summary['billing_cycle'],
            'current_period_ends_at' => $summary['current_period_ends_at'],
            'notes' => $summary['notes'],
            'modules' => collect($summary['modules'])
                ->map(fn (array $module) => [
                    'enabled' => $module['enabled'],
                    'quantity' => $module['quantity'],
                ])
                ->all(),
        ];
    }
}
