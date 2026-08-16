<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\TenantBillingService;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleGatingMaintenanceMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function makeNewTenantAdmin(): array
    {
        $tenant = Tenant::factory()->create();
        app(TenantBillingService::class)->provision($tenant);
        app(TenantRoleService::class)->provision($tenant);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'gating-hotel.test',
            'is_primary' => true,
        ]);
        app(TenantContext::class)->set($tenant);

        $admin = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $tenant->users()->syncWithoutDetaching([
            $admin->id => ['is_owner' => true, 'is_active' => true],
        ]);
        $admin->assignRole('admin');

        app(TenantContext::class)->clear();

        return [$tenant, $admin];
    }

    public function test_new_hotel_without_modules_is_refused_on_maintenance_and_messages(): void
    {
        [$tenant, $admin] = $this->makeNewTenantAdmin();

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('https://gating-hotel.test'.route('maintenance.index', absolute: false))
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('https://gating-hotel.test'.route('messages.index', absolute: false))
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('https://gating-hotel.test'.route('reports.maintenanceSla', absolute: false))
            ->assertForbidden();
    }

    public function test_messages_without_channel_manager_is_rejected_by_validation(): void
    {
        config(['lora.control_panel_hosts' => ['localhost']]);

        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $tenant->id,
        ]);
        app(TenantContext::class)->clear();

        $modules = collect(array_keys(config('lora_modules.modules')))
            ->mapWithKeys(fn (string $code) => [$code => ['enabled' => false, 'quantity' => 1]])
            ->all();
        $modules['messages'] = ['enabled' => true, 'quantity' => 1];

        $this->actingAs($superAdmin)
            ->withSession(['tenant_id' => $tenant->id])
            ->from(route('super-admin.tenants.show', $tenant))
            ->put(route('super-admin.tenants.subscription.update', $tenant), [
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'modules' => $modules,
            ])
            ->assertSessionHasErrors(['modules.messages.enabled']);
    }
}
