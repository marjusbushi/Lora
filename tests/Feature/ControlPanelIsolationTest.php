<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\TenantBillingService;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ControlPanelIsolationTest extends TestCase
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

    public function test_control_panel_dashboard_is_tenantless(): void
    {
        $tenant = Tenant::query()->sole();
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $tenant->id,
        ]);

        $this->actingAs($superAdmin)
            ->get('https://admin.lorapms.test/super-admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Dashboard')
                ->where('tenant', null)
                ->where('auth.user.role', null)
                ->where('auth.user.permissions', [])
                ->where('stats.hotels_total', 1)
                ->where('stats.hotels_active', 1));
    }

    public function test_regular_hotel_user_cannot_enter_control_panel(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get('https://admin.lorapms.test/super-admin')
            ->assertForbidden();
    }

    public function test_control_panel_route_on_a_hotel_host_redirects_to_lora_admin(): void
    {
        $tenant = Tenant::query()->sole();
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'admin.villamucho.test',
            'is_primary' => false,
        ]);
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $tenant->id,
        ]);

        $this->actingAs($superAdmin)
            ->get('https://admin.villamucho.test/super-admin/tenants')
            ->assertRedirect('https://admin.lorapms.test/super-admin/tenants');
    }

    public function test_dedicated_control_panel_never_renders_a_hotel_dashboard(): void
    {
        $tenant = Tenant::query()->sole();
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $tenant->id,
        ]);

        $this->actingAs($superAdmin)
            ->get('https://admin.lorapms.test/dashboard')
            ->assertRedirect('https://admin.lorapms.test/super-admin');
    }

    public function test_dedicated_control_panel_login_rejects_hotel_staff(): void
    {
        User::factory()->create([
            'email' => 'staff@example.test',
            'password' => 'password',
            'is_super_admin' => false,
        ]);

        $this->post('https://admin.lorapms.test/login', [
            'email' => 'staff@example.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_super_admin_login_goes_to_control_panel(): void
    {
        User::factory()->create([
            'email' => 'platform@example.test',
            'password' => 'password',
            'is_super_admin' => true,
        ]);

        $this->post('https://admin.lorapms.test/login', [
            'email' => 'platform@example.test',
            'password' => 'password',
        ])->assertRedirect('https://admin.lorapms.test/super-admin');
    }

    public function test_hotel_pms_is_unreachable_on_product_hosts(): void
    {
        $staff = User::factory()->create(['is_super_admin' => false]);

        // Dedicated control panel host — never a hotel back-office.
        $this->actingAs($staff)
            ->get('https://admin.lorapms.test/pms/rooms')
            ->assertNotFound();

        // Marketing host (default config) — never a hotel back-office either.
        $this->actingAs($staff)
            ->get('https://lorapms.com/pms/rooms')
            ->assertNotFound();
    }

    public function test_super_admin_on_a_product_host_is_sent_to_the_control_panel(): void
    {
        $tenant = Tenant::query()->sole();
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $tenant->id,
        ]);

        $this->actingAs($superAdmin)
            ->get('https://lorapms.com/pms/rooms')
            ->assertRedirect('https://admin.lorapms.test/super-admin');
    }

    public function test_hotel_pages_do_not_expose_control_panel_route_names(): void
    {
        // localhost is the migrated hotel's own domain (not a control panel host
        // under this test's config) — its HTML must carry the FILTERED route map.
        $this->get('http://localhost/login')
            ->assertOk()
            ->assertDontSee('super-admin.tenants', false);

        // The control panel host keeps the full map (its pages need those routes).
        $this->get('https://admin.lorapms.test/login')
            ->assertOk()
            ->assertSee('super-admin.tenants', false);
    }

    public function test_activity_feed_shows_platform_actions_to_super_admin_only(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->run($tenant, function () use ($tenant) {
            AuditLog::record('tenant.integration.update', $tenant, [
                'provider' => 'channex',
                'enabled' => true,
                'updated_fields' => ['api_key', 'property_id'],
            ]);
        });

        $superAdmin = User::factory()->create(['is_super_admin' => true, 'current_tenant_id' => $tenant->id]);

        $response = $this->actingAs($superAdmin)
            ->get('https://admin.lorapms.test/super-admin/activity')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Activity')
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'tenant.integration.update')
                ->where('stats.actions_24h', 1)
                ->where('stats.hotels_24h', 1)
                ->has('hotels', 1));

        $this->actingAs($superAdmin)
            ->get('https://admin.lorapms.test/super-admin/activity?q='.urlencode($tenant->name).'&tenant='.$tenant->id.'&range=30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1)
                ->where('filter.q', $tenant->name)
                ->where('filter.tenant', $tenant->id)
                ->where('filter.range', '30'));

        // Field NAMES may show; secret VALUES must never reach the page.
        $this->assertStringNotContainsString('api_key_value', $response->getContent());
    }

    public function test_activity_feed_is_forbidden_for_a_hotel_admin(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get('https://admin.lorapms.test/super-admin/activity')
            ->assertForbidden();
    }

    public function test_tenant_detail_page_shows_members_domains_and_integrations_to_super_admin(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Hotel Detail']);
        app(TenantBillingService::class)->provision($tenant, enableAll: true);
        app(TenantRoleService::class)->provision($tenant);
        TenantDomain::query()->create(['tenant_id' => $tenant->id, 'domain' => 'detail.test', 'is_primary' => true]);

        // A member with a role IN THIS hotel.
        $context = app(TenantContext::class);
        $context->set($tenant);
        $member = User::factory()->create();
        $tenant->users()->syncWithoutDetaching([$member->id => ['is_owner' => true, 'is_active' => true]]);
        $member->unsetRelation('roles')->assignRole('manager');
        $context->clear();

        // A Channex integration with a secret that must NEVER reach the page.
        $context->run($tenant, function () {
            TenantIntegration::query()->create([
                'provider' => 'channex', 'enabled' => true,
                'credentials' => ['api_key' => 'super-secret-key'],
                'configuration' => ['property_id' => 'PROP-DETAIL'],
            ]);
        });

        // Super admin belongs to the DEFAULT hotel, not this one — so members = the one real member.
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($superAdmin)
            ->get("https://admin.lorapms.test/super-admin/tenants/{$tenant->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Tenants/Show')
                ->where('tenant.name', 'Hotel Detail')
                ->where('tenant.integrations.channex.enabled', true)
                ->where('tenant.integrations.channex.has_api_key', true)
                ->has('members', 1)
                ->where('members.0.role', 'manager')      // this hotel's role, not another's
                ->where('members.0.is_owner', true));

        $this->assertStringNotContainsString('super-secret-key', $response->getContent());
    }

    public function test_tenant_detail_page_is_forbidden_for_a_hotel_admin(): void
    {
        $tenant = Tenant::query()->sole();
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get("https://admin.lorapms.test/super-admin/tenants/{$tenant->id}")
            ->assertForbidden();
    }

    public function test_dashboard_flags_hotels_that_need_attention(): void
    {
        // A suspended hotel.
        $suspended = Tenant::factory()->create(['name' => 'Hotel Suspended', 'status' => 'suspended']);

        // A hotel whose subscription is past due.
        $pastDue = Tenant::factory()->create(['name' => 'Hotel PastDue', 'status' => 'active']);
        app(TenantBillingService::class)->provision($pastDue);
        $pastDue->subscription->update(['status' => 'past_due']);

        // The default hotel is active + healthy → must NOT be flagged.
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($superAdmin)
            ->get('https://admin.lorapms.test/super-admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Dashboard')
                ->has('needsAttention', 2)
                ->where('needsAttention.0.severity', 'danger'));
    }
}
