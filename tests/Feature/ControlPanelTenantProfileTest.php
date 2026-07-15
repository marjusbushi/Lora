<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ControlPanelTenantProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'lora.control_panel_url' => 'https://admin.lorapms.test',
            'lora.control_panel_hosts' => ['admin.lorapms.test'],
            'lora.dedicated_control_panel_hosts' => ['admin.lorapms.test'],
        ]);
    }

    public function test_profile_exposes_all_drawer_options_and_updates_hotel_details(): void
    {
        $tenant = Tenant::query()->sole();
        $admin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $tenant->id,
        ]);

        $this->actingAs($admin)
            ->get("https://admin.lorapms.test/super-admin/tenants/{$tenant->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Tenants/Show')
                ->where('tenant.id', $tenant->id)
                ->has('currencyOptions', 10)
                ->has('timezoneGroups.Europe')
                ->where('roleOptions.0', 'admin'));

        $this->actingAs($admin)
            ->patch("https://admin.lorapms.test/super-admin/tenants/{$tenant->id}", [
                'name' => 'Hotel Riviera Updated',
                'slug' => 'hotel-riviera-updated',
                'timezone' => 'Europe/Rome',
                'currency' => 'GBP',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Hotel Riviera Updated',
            'slug' => 'hotel-riviera-updated',
            'timezone' => 'Europe/Rome',
            'currency' => 'GBP',
        ]);
        $this->assertDatabaseHas('settings', [
            'tenant_id' => $tenant->id,
            'group' => 'hotel',
            'key' => 'currency',
            'value' => 'GBP',
        ]);
    }

    public function test_super_admin_can_add_and_update_a_tenant_member_from_profile(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($tenant);
        $admin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $tenant->id,
        ]);

        $this->actingAs($admin)
            ->post("https://admin.lorapms.test/super-admin/tenants/{$tenant->id}/members", [
                'name' => 'Ana Dervishi',
                'email' => 'ana.profile@example.test',
                'role' => 'manager',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $member = User::withoutGlobalScopes()->where('email', 'ana.profile@example.test')->sole();
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'is_active' => true,
        ]);

        app(TenantContext::class)->run($tenant, function () use ($member) {
            $this->assertTrue($member->unsetRelation('roles')->hasRole('manager'));
        });

        $this->actingAs($admin)
            ->put("https://admin.lorapms.test/super-admin/tenants/{$tenant->id}/members/{$member->id}", [
                'name' => 'Ana Dervishi',
                'email' => 'ana.profile@example.test',
                'role' => 'receptionist',
                'is_active' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, (int) DB::table('tenant_user')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $member->id)
            ->value('is_active'));
        app(TenantContext::class)->run($tenant, function () use ($member) {
            $this->assertTrue($member->unsetRelation('roles')->hasRole('receptionist'));
        });
    }
}
