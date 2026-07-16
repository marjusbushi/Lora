<?php

namespace Tests\Feature;

use App\Listeners\BindAiAccessTokenToTenant;
use App\Mcp\Servers\LoraHotelServer;
use App\Mcp\Tools\GetHotelContextTool;
use App\Models\AiAccessToken;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Events\AccessTokenCreated;
use Tests\TestCase;

class LoraAiMcpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);
        $publicKey = openssl_pkey_get_details($key)['key'];
        config(['passport.private_key' => $privateKey, 'passport.public_key' => $publicKey]);
    }

    public function test_oauth_token_is_permanently_bound_to_the_users_active_hotel(): void
    {
        $tenant = Tenant::query()->sole();
        $user = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $user->tenants()->syncWithoutDetaching([$tenant->id => ['is_active' => true, 'is_owner' => true]]);

        app(BindAiAccessTokenToTenant::class)->handle(new AccessTokenCreated('token-1', (string) $user->id, 'client-1'));

        $this->assertDatabaseHas('ai_access_tokens', [
            'access_token_id' => 'token-1',
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_hotel_context_tool_only_returns_the_active_tenant_and_staff_permissions(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $admin->assignRole('admin');

        LoraHotelServer::actingAs($admin, 'api')
            ->tool(GetHotelContextTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('hotel.id', $tenant->id)
                ->where('hotel.name', $tenant->name)
                ->where('staff.id', $admin->id)
                ->has('staff.permissions')
                ->etc());
    }

    public function test_unbound_mcp_request_is_rejected_before_a_tool_can_run(): void
    {
        $this->postJson('/mcp/lora-hotel', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ])->assertUnauthorized();

        $this->assertSame(0, AiAccessToken::count());
    }

    public function test_hotel_admin_can_open_the_lora_ai_page_and_save_tenant_scoped_permissions(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('lora-ai.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LoraAi/Index')
                ->where('connection.hotel', $tenant->name)
                ->where('connection.connected', false)
                ->has('settings'));

        $this->actingAs($admin)->put(route('lora-ai.update'), [
            'reservations_enabled' => true,
            'messages_enabled' => true,
            'guest_reply_enabled' => false,
            'pricing_enabled' => true,
            'price_apply_enabled' => false,
        ])->assertRedirect();

        $this->assertFalse((bool) Setting::get('ai_mcp.guest_reply_enabled'));
        $this->assertTrue((bool) Setting::get('ai_mcp.reservations_enabled'));
    }
}
