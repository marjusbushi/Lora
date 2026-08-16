<?php

namespace Tests\Feature;

use App\Models\CatalogPriceOverride;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ModuleCatalog;
use App\Services\TenantBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPriceOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'lora.control_panel_hosts' => ['admin.lorapms.test'],
            'lora.dedicated_control_panel_hosts' => ['admin.lorapms.test'],
        ]);

        ModuleCatalog::flush();
    }

    protected function tearDown(): void
    {
        ModuleCatalog::flush();

        parent::tearDown();
    }

    public function test_catalog_without_overrides_mirrors_config(): void
    {
        $this->assertSame(
            config('lora_modules.modules.smart_pricing.unit_price_cents'),
            ModuleCatalog::modules()['smart_pricing']['unit_price_cents'],
        );
    }

    public function test_partial_override_wins_and_null_fields_keep_config(): void
    {
        CatalogPriceOverride::create(['module_code' => 'pos', 'first_unit_price_cents' => 5900]);
        ModuleCatalog::flush();

        $pos = ModuleCatalog::modules()['pos'];

        $this->assertSame(5900, $pos['first_unit_price_cents']);
        $this->assertSame(
            config('lora_modules.modules.pos.unit_price_cents'),
            $pos['unit_price_cents'],
        );
    }

    public function test_override_reaches_billing_summary_without_deploy(): void
    {
        $tenant = Tenant::factory()->create();

        CatalogPriceOverride::create(['module_code' => 'smart_pricing', 'unit_price_cents' => 5900]);
        ModuleCatalog::flush();

        $summary = app(TenantBillingService::class)->summary($tenant);

        $this->assertSame(5900, $summary['modules']['smart_pricing']['unit_price_cents']);
    }

    public function test_super_admin_updates_prices_and_equal_to_config_clears_override(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->put('https://admin.lorapms.test/super-admin/catalog', [
            'modules' => [
                ['code' => 'smart_pricing', 'unit_price_cents' => 5900],
                // E barabartë me config-un → s'duhet të mbetet override.
                ['code' => 'finance', 'unit_price_cents' => (int) config('lora_modules.modules.finance.unit_price_cents')],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('catalog_price_overrides', [
            'module_code' => 'smart_pricing',
            'unit_price_cents' => 5900,
            'updated_by' => $admin->id,
        ]);
        $this->assertDatabaseMissing('catalog_price_overrides', ['module_code' => 'finance']);

        $this->assertSame(5900, ModuleCatalog::modules()['smart_pricing']['unit_price_cents']);
    }

    public function test_enabled_entitlement_keeps_frozen_snapshot_after_catalog_change(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = app(TenantBillingService::class);

        // Aktivizimi i parë ngrin çmimin e sotëm të katalogut.
        $billing->update($tenant, [
            'status' => 'active', 'billing_cycle' => 'monthly',
            'modules' => ['smart_pricing' => ['enabled' => true, 'quantity' => 1]],
        ]);
        $frozen = $tenant->moduleEntitlements()->where('module_code', 'smart_pricing')->first();
        $originalPrice = $frozen->unit_price_cents;

        // Katalogu ndryshon PAS aktivizimit.
        CatalogPriceOverride::create(['module_code' => 'smart_pricing', 'unit_price_cents' => 9900]);
        ModuleCatalog::flush();

        // Një edit i palidhur i abonimit (sasi housekeeping) NUK e ripreson klientin.
        $billing->update($tenant->fresh(), [
            'status' => 'active', 'billing_cycle' => 'monthly',
            'modules' => [
                'smart_pricing' => ['enabled' => true, 'quantity' => 1],
                'housekeeping' => ['enabled' => true, 'quantity' => 2],
            ],
        ]);

        $smart = $tenant->moduleEntitlements()->where('module_code', 'smart_pricing')->first();
        $this->assertSame($originalPrice, $smart->unit_price_cents, 'Snapshot-i i ngrirë u mbishkrua nga katalogu i ri');

        // Ndërsa aktivizimi i RI (housekeeping) merr çmimin aktual të katalogut.
        $housekeeping = $tenant->moduleEntitlements()->where('module_code', 'housekeeping')->first();
        $this->assertSame(
            config('lora_modules.modules.housekeeping.unit_price_cents'),
            $housekeeping->unit_price_cents,
        );
    }

    public function test_reenabled_module_gets_the_current_catalog_price(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = app(TenantBillingService::class);

        $billing->update($tenant, [
            'status' => 'active', 'billing_cycle' => 'monthly',
            'modules' => ['smart_pricing' => ['enabled' => true, 'quantity' => 1]],
        ]);

        CatalogPriceOverride::create(['module_code' => 'smart_pricing', 'unit_price_cents' => 9900]);
        ModuleCatalog::flush();

        // Çaktivizim → riaktivizim = aktivizim i ri → çmimi i ri i katalogut.
        $billing->update($tenant->fresh(), ['status' => 'active', 'billing_cycle' => 'monthly', 'modules' => []]);
        $billing->update($tenant->fresh(), [
            'status' => 'active', 'billing_cycle' => 'monthly',
            'modules' => ['smart_pricing' => ['enabled' => true, 'quantity' => 1]],
        ]);

        $smart = $tenant->moduleEntitlements()->where('module_code', 'smart_pricing')->first();
        $this->assertSame(9900, $smart->unit_price_cents);
    }

    public function test_non_super_admin_is_refused(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['is_super_admin' => false, 'current_tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->put('https://admin.lorapms.test/super-admin/catalog', [
                'modules' => [['code' => 'smart_pricing', 'unit_price_cents' => 100]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('catalog_price_overrides', 0);
    }

    public function test_unknown_module_code_and_negative_price_are_rejected(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->from('https://admin.lorapms.test/super-admin/catalog')
            ->put('https://admin.lorapms.test/super-admin/catalog', [
                'modules' => [['code' => 'jo-modul', 'unit_price_cents' => 100]],
            ])
            ->assertSessionHasErrors(['modules.0.code']);

        $this->actingAs($admin)
            ->from('https://admin.lorapms.test/super-admin/catalog')
            ->put('https://admin.lorapms.test/super-admin/catalog', [
                'modules' => [['code' => 'smart_pricing', 'unit_price_cents' => -5]],
            ])
            ->assertSessionHasErrors(['modules.0.unit_price_cents']);

        $this->assertDatabaseCount('catalog_price_overrides', 0);
    }
}
