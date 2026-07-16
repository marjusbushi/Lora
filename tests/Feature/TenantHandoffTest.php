<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\TenantHandoff;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantHandoffTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'lora.control_panel_url' => 'https://admin.lorapms.test',
            'lora.control_panel_hosts' => ['admin.lorapms.test'],
            'lora.dedicated_control_panel_hosts' => ['admin.lorapms.test'],
            'lora.tenant_handoff_ttl_seconds' => 60,
        ]);

        $this->tenant = Tenant::query()->sole();
        TenantDomain::query()->where('tenant_id', $this->tenant->id)->delete();
        TenantDomain::query()->create([
            'tenant_id' => $this->tenant->id,
            'domain' => 'hotel-a.lorapms.test',
            'is_primary' => true,
        ]);

        app(TenantContext::class)->set($this->tenant);
        $this->superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $this->tenant->id,
        ]);
        app(TenantContext::class)->clear();
    }

    public function test_valid_handoff_creates_a_fresh_hotel_session_and_cannot_be_replayed(): void
    {
        $token = app(TenantHandoff::class)->issue($this->superAdmin, $this->tenant, 'hotel-a.lorapms.test');
        $url = 'http://hotel-a.lorapms.test/tenant-handoff?token='.$token;

        $this->get($url)
            ->assertRedirect('http://hotel-a.lorapms.test/dashboard')
            ->assertSessionHas('tenant_id', $this->tenant->id);

        $this->assertAuthenticatedAs($this->superAdmin);

        $this->post('http://hotel-a.lorapms.test/logout')->assertRedirect('/');

        $this->get($url)->assertForbidden();
        $this->assertGuest();

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'tenant.switch',
            'causer_id' => $this->superAdmin->id,
        ]);
    }

    public function test_wrong_hotel_cannot_use_or_burn_another_hotels_handoff(): void
    {
        $other = Tenant::factory()->create();
        TenantDomain::query()->create([
            'tenant_id' => $other->id,
            'domain' => 'hotel-b.lorapms.test',
            'is_primary' => true,
        ]);

        $token = app(TenantHandoff::class)->issue($this->superAdmin, $this->tenant, 'hotel-a.lorapms.test');

        $this->get('http://hotel-b.lorapms.test/tenant-handoff?token='.$token)
            ->assertForbidden();
        $this->assertGuest();

        $this->get('http://hotel-a.lorapms.test/tenant-handoff?token='.$token)
            ->assertRedirect('http://hotel-a.lorapms.test/dashboard')
            ->assertSessionHas('tenant_id', $this->tenant->id);

        $this->assertAuthenticatedAs($this->superAdmin);
    }

    public function test_handoff_redirects_only_to_an_onboarding_destination(): void
    {
        $token = app(TenantHandoff::class)->issue($this->superAdmin, $this->tenant, 'hotel-a.lorapms.test');

        $this->get('http://hotel-a.lorapms.test/tenant-handoff?token='.$token.'&redirect='.urlencode('/settings?tab=currencies'))
            ->assertRedirect('http://hotel-a.lorapms.test/pms/settings?tab=currencies');

        $this->post('http://hotel-a.lorapms.test/logout');
        $token = app(TenantHandoff::class)->issue($this->superAdmin, $this->tenant, 'hotel-a.lorapms.test');

        $this->get('http://hotel-a.lorapms.test/tenant-handoff?token='.$token.'&redirect='.urlencode('https://evil.example'))
            ->assertRedirect('http://hotel-a.lorapms.test/dashboard');
    }

    public function test_expired_or_malformed_handoff_is_rejected(): void
    {
        $token = app(TenantHandoff::class)->issue($this->superAdmin, $this->tenant, 'hotel-a.lorapms.test');
        $this->travel(61)->seconds();

        $this->get('http://hotel-a.lorapms.test/tenant-handoff?token='.$token)
            ->assertForbidden()
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('http://hotel-a.lorapms.test/tenant-handoff?token=invalid')
            ->assertForbidden();

        $this->assertGuest();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_handoff_cannot_restore_a_deleted_super_administrator(): void
    {
        $token = app(TenantHandoff::class)->issue($this->superAdmin, $this->tenant, 'hotel-a.lorapms.test');
        $this->superAdmin->delete();

        $this->get('http://hotel-a.lorapms.test/tenant-handoff?token='.$token)
            ->assertForbidden();

        $this->assertGuest();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_regular_user_cannot_create_handoff_through_control_panel(): void
    {
        app(TenantContext::class)->set($this->tenant);
        $regular = User::factory()->create();
        app(TenantContext::class)->clear();

        $this->actingAs($regular)
            ->post('https://admin.lorapms.test/super-admin/tenants/'.$this->tenant->id.'/switch')
            ->assertForbidden();
    }
}
