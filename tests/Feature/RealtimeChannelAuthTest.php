<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Themeli realtime (task #52): autorizimi i kanaleve private per-tenant.
 * Kufiri i vërtetë i izolimit është KY — allowed_origins mbetet i hapur,
 * por pa autorizim kanali askush s'merr asnjë ngjarje.
 */
class RealtimeChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // KUJDES: me driver 'log'/'null' auth-i i kanaleve kthen GJITHMONË 200
        // bosh (LogBroadcaster::auth është no-op) — refuzimet testohen VETËM
        // me driver-in real 'reverb' (firmosja është lokale, pa server).
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // Kanalet regjistrohen te drajveri AKTIV në nisje ('log' në teste) —
        // pas ndërrimit të drajverit, regjistri i 'reverb' është bosh dhe çdo
        // auth kthen 403. Ri-ngarkimi i skedarit i regjistron te 'reverb'.
        require base_path('routes/channels.php');

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function authorize(string $channel)
    {
        return $this->actingAs($this->admin)->post('/broadcasting/auth', [
            'channel_name' => 'private-'.$channel,
            'socket_id' => '1234.5678',
        ]);
    }

    public function test_member_of_the_tenant_is_authorized_on_its_channels(): void
    {
        $this->authorize('tenant.'.$this->tenant->id.'.messages')->assertOk();
        $this->authorize('tenant.'.$this->tenant->id.'.reservations')->assertOk();
        $this->authorize('tenant.'.$this->tenant->id.'.pos')->assertOk();
    }

    public function test_foreign_tenant_channel_is_refused_even_with_a_forged_id(): void
    {
        // Id e falsifikuar në emrin e kanalit — tenant-i i sesionit s'përputhet.
        $this->authorize('tenant.'.($this->tenant->id + 999).'.messages')->assertForbidden();
        $this->authorize('tenant.'.($this->tenant->id + 999).'.reservations')->assertForbidden();
        $this->authorize('tenant.'.($this->tenant->id + 999).'.pos')->assertForbidden();
    }

    public function test_user_without_the_view_permission_is_refused(): void
    {
        $bare = User::factory()->create(['current_tenant_id' => $this->tenant->id]);

        $this->actingAs($bare)->post('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$this->tenant->id.'.messages',
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    public function test_guests_cannot_authorize_any_tenant_channel(): void
    {
        $this->post('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$this->tenant->id.'.messages',
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }
}
