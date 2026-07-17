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
        $this->assertSame(11200, $billing['monthly_fixed_cents']);
        $this->assertSame(107520, $billing['annual_cents']);
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
            $this->assertTrue($receptionist->hasPermissionTo('view_finance'));
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
            'current_period_ends_at' => '2027-07-11',
            'notes' => 'Kontratë vjetore test.',
            'modules' => [
                'core' => ['enabled' => false, 'quantity' => 1],
                'channel_manager' => ['enabled' => true, 'quantity' => 60],
                'booking_engine' => ['enabled' => true, 'quantity' => 1],
                'housekeeping' => ['enabled' => true, 'quantity' => 2],
                'pos' => ['enabled' => true, 'quantity' => 3],
                'finance' => ['enabled' => true, 'quantity' => 1],
                'smart_pricing' => ['enabled' => true, 'quantity' => 1],
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
        $this->assertSame(55200, $summary['monthly_fixed_cents']);
        $this->assertSame(529920, $summary['annual_cents']);
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
