<?php

namespace Tests\Feature;

use App\Listeners\BindAiAccessTokenToTenant;
use App\Mcp\Servers\LoraHotelServer;
use App\Mcp\Tools\CheckAvailabilityTool;
use App\Mcp\Tools\CreatePriceProposalTool;
use App\Mcp\Tools\ExecuteApprovedActionTool;
use App\Mcp\Tools\GetDailyOperationsBriefTool;
use App\Mcp\Tools\GetGuestConversationTool;
use App\Mcp\Tools\GetHotelContextTool;
use App\Mcp\Tools\GetPricingCalendarTool;
use App\Mcp\Tools\GetReservationContextTool;
use App\Mcp\Tools\PrepareGuestReplyTool;
use App\Mcp\Tools\SearchHotelTool;
use App\Mcp\Tools\SearchReservationsTool;
use App\Models\AiAccessToken;
use App\Models\AiActionProposal;
use App\Models\AiOAuthGrant;
use App\Models\CompRate;
use App\Models\Guest;
use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\AiPriceGuardrails;
use App\Services\CommercialPriceRounding;
use App\Services\PricingEngine;
use App\Services\TenantBillingService;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\AccessToken as PassportAccessToken;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
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
        $this->persistGrant($user, $tenant);

        $this->persistPassportToken($user, 'token-1', ['mcp:use']);
        app(BindAiAccessTokenToTenant::class)->handle(new AccessTokenCreated(
            'token-1',
            (string) $user->id,
            $this->passportClientId(),
        ));

        $this->assertDatabaseHas('ai_access_tokens', [
            'access_token_id' => 'token-1',
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_oauth_tokens_without_mcp_scope_are_not_bound_to_a_hotel(): void
    {
        $tenant = Tenant::query()->sole();
        $user = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $user->tenants()->syncWithoutDetaching([$tenant->id => ['is_active' => true, 'is_owner' => true]]);
        $this->persistGrant($user, $tenant);

        foreach ([
            'token-empty' => [],
            'token-unrelated' => ['profile:read'],
        ] as $tokenId => $scopes) {
            $this->persistPassportToken($user, $tokenId, $scopes);
            app(BindAiAccessTokenToTenant::class)->handle(new AccessTokenCreated(
                $tokenId,
                (string) $user->id,
                $this->passportClientId(),
            ));

            $this->assertDatabaseMissing('ai_access_tokens', ['access_token_id' => $tokenId]);
        }
    }

    public function test_explicit_client_grant_keeps_new_tokens_bound_after_the_user_switches_hotels(): void
    {
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create();
        $user = User::factory()->create(['current_tenant_id' => $first->id]);
        $user->tenants()->syncWithoutDetaching([
            $first->id => ['is_active' => true, 'is_owner' => true],
            $second->id => ['is_active' => true, 'is_owner' => true],
        ]);
        $this->persistGrant($user, $first);

        foreach (['original-token', 'refreshed-token'] as $index => $tokenId) {
            if ($index === 1) {
                $user->forceFill(['current_tenant_id' => $second->id])->save();
            }

            $this->persistPassportToken($user, $tokenId, ['mcp:use']);
            app(BindAiAccessTokenToTenant::class)->handle(new AccessTokenCreated(
                $tokenId,
                (string) $user->id,
                $this->passportClientId(),
            ));
        }

        $this->assertDatabaseHas('ai_access_tokens', [
            'access_token_id' => 'refreshed-token',
            'tenant_id' => $first->id,
            'user_id' => $user->id,
            'client_id' => $this->passportClientId(),
        ]);
        $this->assertDatabaseMissing('ai_access_tokens', [
            'access_token_id' => 'refreshed-token',
            'tenant_id' => $second->id,
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

    public function test_super_admin_can_use_a_bound_hotels_read_tools_without_a_tenant_role(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $superAdmin = User::factory()->create([
            'current_tenant_id' => $tenant->id,
            'is_super_admin' => true,
        ]);

        $this->assertTrue($superAdmin->is_super_admin);
        $this->assertTrue($superAdmin->getRoleNames()->isEmpty());

        LoraHotelServer::actingAs($superAdmin, 'api')
            ->tool(SearchReservationsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->has('count')
                ->has('reservations'));
    }

    public function test_universal_search_returns_tenant_scoped_operational_links_only(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $superAdmin = User::factory()->create([
            'current_tenant_id' => $tenant->id,
            'is_super_admin' => true,
        ]);
        $type = RoomType::query()->create(['name' => 'Deluxe', 'base_price' => 90, 'max_occupancy' => 2]);
        $room = Room::query()->create([
            'room_type_id' => $type->id,
            'room_number' => '707',
            'floor' => 7,
            'status' => 'available',
        ]);
        $guest = Guest::query()->create([
            'first_name' => 'Elira',
            'last_name' => 'Test',
            'email' => 'elira@example.test',
            'document_number' => 'SECRET-DOCUMENT',
        ]);
        $reservation = Reservation::query()->create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $superAdmin->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 120,
            'adults' => 1,
        ]);
        $otherTenant = Tenant::factory()->create();
        $otherReservationId = app(TenantContext::class)->run($otherTenant, function () use ($superAdmin) {
            $otherType = RoomType::query()->create(['name' => 'Other Deluxe', 'base_price' => 95, 'max_occupancy' => 2]);
            $otherRoom = Room::query()->create([
                'room_type_id' => $otherType->id,
                'room_number' => '999',
                'floor' => 9,
                'status' => 'available',
            ]);
            $otherGuest = Guest::query()->create(['first_name' => 'Elira', 'last_name' => 'Other Hotel']);

            return Reservation::query()->create([
                'room_id' => $otherRoom->id,
                'guest_id' => $otherGuest->id,
                'created_by' => $superAdmin->id,
                'check_in_date' => now()->addDay()->toDateString(),
                'check_out_date' => now()->addDays(2)->toDateString(),
                'status' => 'confirmed',
                'total_amount' => 999,
                'adults' => 1,
            ])->id;
        });
        $this->assertNotSame($reservation->id, $otherReservationId);

        LoraHotelServer::actingAs($superAdmin, 'api')
            ->tool(SearchHotelTool::class, ['query' => 'Elira', 'module' => 'all'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('count', 1)
                ->where('searched_modules.0', 'reservations')
                ->where('results.0.id', $reservation->id)
                ->where('results.0.module', 'reservations')
                ->where('results.0.href', url('/pms/reservations/'.$reservation->id))
                ->missing('results.0.document_number')
                ->etc());

        LoraHotelServer::actingAs($superAdmin, 'api')
            ->tool(GetDailyOperationsBriefTool::class, [
                'date' => now()->addDay()->toDateString(),
            ])->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('reservations.arrivals_count', 1)
                ->where('reservations.arrivals.0.id', $reservation->id)
                ->where('reservations.arrivals.0.href', url('/pms/reservations/'.$reservation->id))
                ->etc());
    }

    public function test_pricing_calendar_exposes_market_and_chatgpt_guardrails_and_applies_only_an_approved_proposal(): void
    {
        Queue::fake();
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $tenant->forceFill(['metadata' => [
            'billing_access' => [
                'status' => 'active',
                'modules' => [TenantBillingService::SMART_PRICING => true],
            ],
        ]])->saveQuietly();
        $superAdmin = User::factory()->create([
            'current_tenant_id' => $tenant->id,
            'is_super_admin' => true,
        ]);
        $type = RoomType::query()->create([
            'name' => 'Suite AI',
            'base_price' => 100,
            'min_price' => 70,
            'max_price' => 160,
            'max_occupancy' => 2,
        ]);
        Room::query()->create([
            'room_type_id' => $type->id,
            'room_number' => '801',
            'floor' => 8,
            'status' => 'available',
        ]);
        $date = now()->addDay()->toDateString();
        CompRate::query()->create([
            'competitor' => 'Hotel Market',
            'date' => $date,
            'price' => 115,
            'currency' => 'EUR',
            'source' => 'test',
            'snapshot_date' => now()->toDateString(),
        ]);
        Setting::set('ai_mcp.pricing_enabled', true, 'boolean');
        Setting::set('ai_mcp.ai_price_recommendations_enabled', true, 'boolean');
        Setting::set('ai_mcp.price_apply_enabled', true, 'boolean');

        $engineDay = PricingEngine::forRange($type, now()->addDay()->startOfDay(), now()->addDay()->startOfDay())[$date];
        $chatGptPrice = round((float) $engineDay['suggested_price'] * 1.05, 2);
        $limits = AiPriceGuardrails::limits($type, $engineDay);
        $commercialPrice = CommercialPriceRounding::apply($chatGptPrice, $limits['min'], $limits['max'])['after'];

        LoraHotelServer::actingAs($superAdmin, 'api')
            ->tool(GetPricingCalendarTool::class, [
                'room_type_id' => $type->id,
                'date_from' => $date,
                'date_to' => $date,
            ])->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('days.0.market.median', 115.0)
                ->where('days.0.chatgpt_recommendation.allowed', true)
                ->where('days.0.chatgpt_recommendation.guardrails.max_deviation_pct', 15.0)
                ->etc());

        LoraHotelServer::actingAs($superAdmin, 'api')
            ->tool(CreatePriceProposalTool::class, [
                'room_type_id' => $type->id,
                'date_from' => $date,
                'date_to' => $date,
                'proposal_source' => 'chatgpt',
                'recommendations' => [[
                    'date' => $date,
                    'price' => $chatGptPrice,
                    'reason' => 'Kërkesa dhe tregu mbështesin një alternativë të kontrolluar.',
                    'confidence' => 82,
                ]],
                'idempotency_key' => 'hybrid-price-test-001',
            ])->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('preview.proposal_source', 'chatgpt')
                ->where('preview.days.0.price', (int) $commercialPrice)
                ->where('preview.days.0.calculated_price', $chatGptPrice)
                ->where('requires_explicit_confirmation', true)
                ->etc());

        $proposal = AiActionProposal::query()->latest('created_at')->firstOrFail();
        $this->assertSame(0, RateOverride::query()->count());

        LoraHotelServer::actingAs($superAdmin, 'api')
            ->tool(ExecuteApprovedActionTool::class, [
                'proposal_id' => $proposal->id,
                'confirm' => true,
            ])->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('state', 'applied')
                ->where('count', 1)
                ->etc());

        $this->assertSame($commercialPrice, (float) RateOverride::query()->whereDate('date', $date)->sole()->price);
    }

    public function test_super_admin_can_check_room_availability_for_the_bound_hotel(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $superAdmin = User::factory()->create([
            'current_tenant_id' => $tenant->id,
            'is_super_admin' => true,
        ]);
        $roomType = RoomType::query()->create([
            'name' => 'Deluxe Demo',
            'base_price' => 80,
            'max_occupancy' => 2,
            'amenities' => ['WiFi'],
        ]);
        Room::query()->create([
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'floor' => 1,
            'status' => 'available',
        ]);

        LoraHotelServer::actingAs($superAdmin, 'api')
            ->tool(CheckAvailabilityTool::class, [
                'check_in' => '2026-07-16',
                'check_out' => '2026-07-17',
                'adults' => 1,
            ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('currency', 'EUR')
                ->where('room_types.0.name', 'Deluxe Demo')
                ->where('room_types.0.available', 1)
                ->where('room_types.0.stay_total', 80.0)
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

    public function test_bound_tokens_without_mcp_scope_are_rejected_before_the_server_bootstraps(): void
    {
        $tenant = Tenant::query()->sole();
        $user = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $user->tenants()->syncWithoutDetaching([$tenant->id => ['is_active' => true, 'is_owner' => true]]);
        $this->persistGrant($user, $tenant);

        foreach ([
            'token-empty' => [],
            'token-unrelated' => ['profile:read'],
        ] as $tokenId => $scopes) {
            $this->persistPassportToken($user, $tokenId, $scopes);
            AiAccessToken::query()->create([
                'access_token_id' => $tokenId,
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'client_id' => $this->passportClientId(),
            ]);
            $this->actAsWithPassportToken($user, $tokenId, $scopes);

            $this->initializeMcp()->assertForbidden();
        }
    }

    public function test_bound_token_with_mcp_scope_can_initialize_the_server(): void
    {
        $tenant = Tenant::query()->sole();
        $user = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $user->tenants()->syncWithoutDetaching([$tenant->id => ['is_active' => true, 'is_owner' => true]]);
        $this->persistGrant($user, $tenant);

        $this->persistPassportToken($user, 'token-mcp', ['mcp:use']);
        app(BindAiAccessTokenToTenant::class)->handle(new AccessTokenCreated(
            'token-mcp',
            (string) $user->id,
            $this->passportClientId(),
        ));
        $this->actAsWithPassportToken($user, 'token-mcp', ['mcp:use']);

        $this->initializeMcp()
            ->assertOk()
            ->assertJsonPath('id', 1)
            ->assertJsonMissingPath('error');
    }

    public function test_mcp_client_can_register_through_oauth_discovery(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Lora test client',
            'redirect_uris' => ['http://127.0.0.1:53682/callback'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('grant_types.0', 'authorization_code')
            ->assertJsonPath('response_types.0', 'code')
            ->assertJsonPath('scope', 'mcp:use offline_access')
            ->assertJsonPath('token_endpoint_auth_method', 'none')
            ->assertJsonStructure(['client_id', 'redirect_uris']);

        $this->assertDatabaseHas('oauth_clients', [
            'id' => $response->json('client_id'),
            'name' => 'Lora test client',
            'revoked' => false,
        ]);
    }

    public function test_dynamic_client_registration_rejects_untrusted_redirect_origins(): void
    {
        config(['mcp.redirect_domains' => ['https://chatgpt.com']]);

        $this->postJson('/oauth/register', [
            'client_name' => 'Spoofed ChatGPT client',
            'redirect_uris' => ['https://attacker.example/oauth/callback'],
        ])->assertBadRequest()
            ->assertJsonPath('error', 'invalid_redirect_uri');

        $this->assertSame(0, DB::table('oauth_clients')
            ->where('name', 'Spoofed ChatGPT client')
            ->count());
    }

    public function test_oauth_metadata_uses_the_authoritative_hotel_request_origin(): void
    {
        $this->twoHotelOAuthUser();

        $this->getJson('https://hotel-b.test/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', 'https://hotel-b.test')
            ->assertJsonPath('authorization_endpoint', 'https://hotel-b.test/oauth/authorize')
            ->assertJsonPath('token_endpoint', 'https://hotel-b.test/oauth/token')
            ->assertJsonPath('scopes_supported.0', 'mcp:use')
            ->assertJsonPath('scopes_supported.1', 'offline_access');
    }

    public function test_all_mcp_tool_schemas_can_be_serialized(): void
    {
        $tools = [
            GetHotelContextTool::class,
            GetDailyOperationsBriefTool::class,
            SearchHotelTool::class,
            SearchReservationsTool::class,
            GetReservationContextTool::class,
            CheckAvailabilityTool::class,
            GetGuestConversationTool::class,
            GetPricingCalendarTool::class,
            PrepareGuestReplyTool::class,
            CreatePriceProposalTool::class,
            ExecuteApprovedActionTool::class,
        ];

        foreach ($tools as $tool) {
            $definition = app($tool)->toArray();

            $this->assertSame('object', $definition['inputSchema']['type']);
            $this->assertArrayHasKey('properties', $definition['inputSchema']);
        }
    }

    public function test_hotel_admin_can_open_the_lora_ai_page_and_save_tenant_scoped_permissions(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $admin->assignRole('admin');
        $billing = app(TenantBillingService::class);

        $this->actingAs($admin)->get(route('lora-ai.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LoraAi/Index')
                ->where('connection.hotel', $tenant->name)
                ->where('connection.connected', false)
                ->where('settings.hotel_name', Setting::get('hotel.name', 'Hotel'))
                ->where('modules.finance', $billing->enabled(TenantBillingService::FINANCE, $tenant))
                ->where('aiModules.channel_manager', $billing->enabled(TenantBillingService::CHANNEL_MANAGER, $tenant))
                ->where('aiModules.smart_pricing', $billing->enabled(TenantBillingService::SMART_PRICING, $tenant))
                ->where('pricingPolicy.maxDeviationPct', 15)
                ->has('aiSettings'));

        $this->actingAs($admin)->put(route('lora-ai.update'), [
            'reservations_enabled' => true,
            'messages_enabled' => true,
            'guest_reply_enabled' => false,
            'pricing_enabled' => true,
            'universal_search_enabled' => true,
            'ai_price_recommendations_enabled' => true,
            'finance_enabled' => false,
            'housekeeping_enabled' => false,
            'maintenance_enabled' => false,
            'pos_enabled' => false,
            'inventory_enabled' => false,
            'price_apply_enabled' => false,
        ])->assertRedirect();

        $this->assertFalse((bool) Setting::get('ai_mcp.guest_reply_enabled'));
        $this->assertTrue((bool) Setting::get('ai_mcp.reservations_enabled'));
    }

    public function test_disconnect_revokes_access_and_refresh_tokens_for_the_hotel_grant(): void
    {
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $admin->assignRole('admin');
        $this->persistGrant($admin, $tenant);

        $this->persistPassportToken($admin, 'disconnect-token', ['mcp:use']);
        AiAccessToken::query()->create([
            'access_token_id' => 'disconnect-token',
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'client_id' => $this->passportClientId(),
        ]);
        DB::table('oauth_refresh_tokens')->insert([
            'id' => 'refresh-token',
            'access_token_id' => 'disconnect-token',
            'revoked' => false,
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)
            ->delete(route('lora-ai.disconnect'))
            ->assertRedirect();

        $this->assertDatabaseHas('oauth_access_tokens', ['id' => 'disconnect-token', 'revoked' => true]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['id' => 'refresh-token', 'revoked' => true]);
        $this->assertDatabaseMissing('ai_access_tokens', ['access_token_id' => 'disconnect-token']);
        $this->assertDatabaseMissing('ai_oauth_grants', [
            'user_id' => $admin->id,
            'client_id' => $this->passportClientId(),
        ]);
    }

    public function test_pkce_authorization_and_refresh_bind_to_the_registered_host_hotel(): void
    {
        [$first, $second, $user] = $this->twoHotelOAuthUser();
        $redirectUri = 'http://127.0.0.1:53682/callback';
        $clientId = $this->registerOAuthClient([
            'https://chatgpt.com/oauth/not-selected',
            $redirectUri,
        ]);
        $verifier = str_repeat('pkce-verifier-', 5);
        $state = 'host-authority-state';

        $consent = $this->actingAs($user)
            ->withSession(['tenant_id' => $first->id])
            ->get($this->authorizationUrl(
                'hotel-b.test',
                $clientId,
                $redirectUri,
                $verifier,
                $state,
            ));

        $consent->assertOk()
            ->assertSee($second->name)
            ->assertSee('http://127.0.0.1:53682');
        preg_match(
            '/Hotel që do të lidhet:<\/p>\s*<p[^>]*>([^<]+)<\/p>/',
            (string) $consent->getContent(),
            $displayedHotel,
        );
        $this->assertSame($second->name, html_entity_decode(trim($displayedHotel[1] ?? '')));

        $authorizationCode = $this->approveAuthorization(
            'hotel-b.test',
            $clientId,
            $state,
            (string) session('authToken'),
        );

        $this->assertDatabaseHas('ai_oauth_grants', [
            'tenant_id' => $second->id,
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
        $this->assertDatabaseMissing('ai_oauth_grants', [
            'tenant_id' => $first->id,
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);

        $token = $this->exchangeAuthorizationCode(
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $authorizationCode,
            $verifier,
        )->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);
        $this->exchangeAuthorizationCode(
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $authorizationCode,
            $verifier,
        )->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $firstAccessTokenId = DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->sole()
            ->id;
        $this->assertDatabaseHas('ai_access_tokens', [
            'access_token_id' => $firstAccessTokenId,
            'tenant_id' => $second->id,
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);

        $refreshed = $this->refreshAccessToken(
            'hotel-b.test',
            $clientId,
            (string) $token->json('refresh_token'),
        )->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);

        $newAccessTokenId = DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('id', '!=', $firstAccessTokenId)
            ->sole()
            ->id;
        $this->assertDatabaseHas('ai_access_tokens', [
            'access_token_id' => $newAccessTokenId,
            'tenant_id' => $second->id,
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
        $this->refreshAccessToken(
            'hotel-b.test',
            $clientId,
            (string) $token->json('refresh_token'),
        )->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame($first->id, $user->fresh()->current_tenant_id);
        $this->assertNotSame($token->json('refresh_token'), $refreshed->json('refresh_token'));
    }

    public function test_an_active_client_grant_rejects_authorization_on_another_hotel_host(): void
    {
        [$first, $second, $user] = $this->twoHotelOAuthUser();
        $redirectUri = 'http://127.0.0.1:53682/callback';
        $clientId = $this->registerOAuthClient($redirectUri);
        $verifier = str_repeat('conflict-verifier-', 4);

        $this->authorizeClientOnHost(
            $user,
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $verifier,
            'hotel-b-grant',
        );

        $response = $this->actingAs($user)
            ->get($this->authorizationUrl(
                'hotel-a.test',
                $clientId,
                $redirectUri,
                $verifier,
                'hotel-a-conflict',
            ));

        $response->assertRedirect();
        parse_str(
            (string) parse_url($response->headers->get('Location'), PHP_URL_QUERY),
            $errorQuery,
        );
        $this->assertSame('access_denied', $errorQuery['error'] ?? null);
        $this->assertSame('hotel-a-conflict', $errorQuery['state'] ?? null);

        $this->assertDatabaseHas('ai_oauth_grants', [
            'tenant_id' => $second->id,
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
        $this->assertDatabaseMissing('ai_oauth_grants', [
            'tenant_id' => $first->id,
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
    }

    public function test_consent_session_cannot_be_approved_on_another_hotel_host(): void
    {
        [$first, , $user] = $this->twoHotelOAuthUser();
        $redirectUri = 'http://127.0.0.1:53682/callback';
        $clientId = $this->registerOAuthClient($redirectUri);
        $verifier = str_repeat('host-swap-verifier-', 4);
        $state = 'host-swap-state';

        $consent = $this->actingAs($user)->get($this->authorizationUrl(
            'hotel-a.test',
            $clientId,
            $redirectUri,
            $verifier,
            $state,
        ));
        $consent->assertOk()->assertSee($first->name);
        $authToken = (string) session('authToken');

        $this->post('https://hotel-b.test/oauth/authorize', [
            'client_id' => $clientId,
            'state' => $state,
            'auth_token' => $authToken,
        ])->assertForbidden();

        $this->assertDatabaseMissing('ai_oauth_grants', [
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
        $this->assertSame(0, DB::table('oauth_auth_codes')
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->count());
    }

    public function test_disconnect_revokes_the_complete_oauth_grant_and_allows_fresh_authorization(): void
    {
        [, $second, $user] = $this->twoHotelOAuthUser();
        $redirectUri = 'http://127.0.0.1:53682/callback';
        $clientId = $this->registerOAuthClient($redirectUri);
        $verifier = str_repeat('disconnect-verifier-', 4);
        $firstCode = $this->authorizeClientOnHost(
            $user,
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $verifier,
            'initial-authorization',
        );
        $token = $this->exchangeAuthorizationCode(
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $firstCode,
            $verifier,
        )->assertOk();
        $pendingCode = $this->authorizeClientOnHost(
            $user,
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $verifier,
            'pending-authorization',
        );
        $pendingCodeId = DB::table('oauth_auth_codes')
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->sole()
            ->id;

        $this->actingAs($user)
            ->withSession(['tenant_id' => $second->id])
            ->delete('https://hotel-b.test/pms/lora-ai/connection')
            ->assertRedirect();

        $this->assertDatabaseMissing('ai_oauth_grants', [
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
        $this->assertSame(0, AiAccessToken::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')
            ->join('oauth_access_tokens', 'oauth_access_tokens.id', '=', 'oauth_refresh_tokens.access_token_id')
            ->where('oauth_access_tokens.user_id', $user->id)
            ->where('oauth_access_tokens.client_id', $clientId)
            ->where('oauth_refresh_tokens.revoked', false)
            ->count());
        $this->assertDatabaseHas('oauth_auth_codes', ['id' => $pendingCodeId, 'revoked' => true]);

        $this->exchangeAuthorizationCode(
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $pendingCode,
            $verifier,
        )->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->refreshAccessToken(
            'hotel-b.test',
            $clientId,
            (string) $token->json('refresh_token'),
        )->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $freshCode = $this->authorizeClientOnHost(
            $user,
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $verifier,
            'fresh-authorization',
        );
        $this->exchangeAuthorizationCode(
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $freshCode,
            $verifier,
        )->assertOk();

        $this->assertDatabaseHas('ai_oauth_grants', [
            'tenant_id' => $second->id,
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
        $this->assertDatabaseHas('ai_access_tokens', [
            'tenant_id' => $second->id,
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
    }

    public function test_inactive_membership_revokes_the_grant_on_the_next_mcp_request(): void
    {
        [, $second, $user] = $this->twoHotelOAuthUser();
        $redirectUri = 'http://127.0.0.1:53682/callback';
        $clientId = $this->registerOAuthClient($redirectUri);
        $verifier = str_repeat('membership-verifier-', 4);
        $code = $this->authorizeClientOnHost(
            $user,
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $verifier,
            'membership-cleanup',
        );
        $token = $this->exchangeAuthorizationCode(
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $code,
            $verifier,
        )->assertOk();

        DB::table('tenant_user')
            ->where('tenant_id', $second->id)
            ->where('user_id', $user->id)
            ->update(['is_active' => false]);

        $this->initializeMcpWithBearer((string) $token->json('access_token'))
            ->assertForbidden();

        $this->assertDatabaseMissing('ai_oauth_grants', [
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
        $this->assertSame(0, AiAccessToken::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->count());
        $this->refreshAccessToken(
            'hotel-b.test',
            $clientId,
            (string) $token->json('refresh_token'),
        )->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_soft_deleting_a_user_revokes_grants_tokens_refreshes_and_pending_codes(): void
    {
        [, , $user] = $this->twoHotelOAuthUser();
        $redirectUri = 'http://127.0.0.1:53682/callback';
        $clientId = $this->registerOAuthClient($redirectUri);
        $verifier = str_repeat('deleted-user-verifier-', 4);
        $code = $this->authorizeClientOnHost(
            $user,
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $verifier,
            'deleted-user-token',
        );
        $token = $this->exchangeAuthorizationCode(
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $code,
            $verifier,
        )->assertOk();
        $this->authorizeClientOnHost(
            $user,
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $verifier,
            'deleted-user-pending-code',
        );

        $user->delete();

        $this->assertDatabaseMissing('ai_oauth_grants', [
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
        $this->assertSame(0, AiAccessToken::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')
            ->join('oauth_access_tokens', 'oauth_access_tokens.id', '=', 'oauth_refresh_tokens.access_token_id')
            ->where('oauth_access_tokens.user_id', $user->id)
            ->where('oauth_access_tokens.client_id', $clientId)
            ->where('oauth_refresh_tokens.revoked', false)
            ->count());
        $this->assertSame(0, DB::table('oauth_auth_codes')
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->count());
        $this->refreshAccessToken(
            'hotel-b.test',
            $clientId,
            (string) $token->json('refresh_token'),
        )->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_super_admin_membership_deactivation_revokes_and_does_not_resurrect_oauth_on_reactivation(): void
    {
        [, $second, $user] = $this->twoHotelOAuthUser();
        DB::table('tenant_user')
            ->where('tenant_id', $second->id)
            ->where('user_id', $user->id)
            ->update(['is_owner' => false]);
        $redirectUri = 'http://127.0.0.1:53682/callback';
        $clientId = $this->registerOAuthClient($redirectUri);
        $verifier = str_repeat('member-offboard-verifier-', 3);
        $code = $this->authorizeClientOnHost(
            $user,
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $verifier,
            'member-offboard',
        );
        $token = $this->exchangeAuthorizationCode(
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $code,
            $verifier,
        )->assertOk();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->configureControlPanelHost();

        $membershipUrl = "https://admin.lorapms.test/super-admin/tenants/{$second->id}/members/{$user->id}";
        $membership = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'admin',
        ];

        $this->actingAs($superAdmin)
            ->put($membershipUrl, [...$membership, 'is_active' => false])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('ai_oauth_grants', [
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);

        $this->actingAs($superAdmin)
            ->put($membershipUrl, [...$membership, 'is_active' => true])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('ai_oauth_grants', [
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
        $this->refreshAccessToken(
            'hotel-b.test',
            $clientId,
            (string) $token->json('refresh_token'),
        )->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_tenant_suspension_revokes_all_oauth_grants_before_reactivation(): void
    {
        [, $second, $user] = $this->twoHotelOAuthUser();
        $redirectUri = 'http://127.0.0.1:53682/callback';
        $clientId = $this->registerOAuthClient($redirectUri);
        $verifier = str_repeat('tenant-suspend-verifier-', 3);
        $code = $this->authorizeClientOnHost(
            $user,
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $verifier,
            'tenant-suspend',
        );
        $token = $this->exchangeAuthorizationCode(
            'hotel-b.test',
            $clientId,
            $redirectUri,
            $code,
            $verifier,
        )->assertOk();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->configureControlPanelHost();

        foreach (['suspended', 'active'] as $status) {
            $this->actingAs($superAdmin)
                ->patch("https://admin.lorapms.test/super-admin/tenants/{$second->id}/status", [
                    'status' => $status,
                ])
                ->assertRedirect();
        }

        $this->assertDatabaseMissing('ai_oauth_grants', [
            'tenant_id' => $second->id,
            'client_id' => $clientId,
        ]);
        $this->refreshAccessToken(
            'hotel-b.test',
            $clientId,
            (string) $token->json('refresh_token'),
        )->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    /** @param list<string> $scopes */
    private function persistPassportToken(User $user, string $tokenId, array $scopes): void
    {
        $this->persistPassportClient();

        Passport::token()->newQuery()->forceCreate([
            'id' => $tokenId,
            'user_id' => $user->id,
            'client_id' => $this->passportClientId(),
            'scopes' => $scopes,
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ]);
    }

    /** @param list<string> $scopes */
    private function actAsWithPassportToken(User $user, string $tokenId, array $scopes): void
    {
        Passport::actingAs($user, $scopes, 'api');
        $user->withAccessToken(new PassportAccessToken([
            'oauth_access_token_id' => $tokenId,
            'oauth_client_id' => $this->passportClientId(),
            'oauth_user_id' => (string) $user->id,
            'oauth_scopes' => $scopes,
        ]));
    }

    private function initializeMcp()
    {
        return $this->postJson('/mcp/lora-hotel', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'scope-test', 'version' => '1.0'],
            ],
        ]);
    }

    private function initializeMcpWithBearer(string $accessToken)
    {
        return $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->postJson('/mcp/lora-hotel', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'grant-cleanup-test', 'version' => '1.0'],
                ],
            ]);
    }

    private function passportClientId(): string
    {
        return '00000000-0000-0000-0000-000000000001';
    }

    private function persistPassportClient(?string $clientId = null, string $redirectUri = 'http://127.0.0.1:53682/callback'): string
    {
        $clientId ??= $this->passportClientId();

        Passport::client()->newQuery()->firstOrCreate(
            ['id' => $clientId],
            [
                'name' => 'Lora test client',
                'secret' => null,
                'redirect_uris' => [$redirectUri],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'revoked' => false,
            ],
        );

        return $clientId;
    }

    private function persistGrant(User $user, Tenant $tenant, ?string $clientId = null): AiOAuthGrant
    {
        $clientId = $this->persistPassportClient($clientId);

        return AiOAuthGrant::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'client_id' => $clientId,
        ]);
    }

    /** @return array{0:Tenant,1:Tenant,2:User} */
    private function twoHotelOAuthUser(): array
    {
        $first = Tenant::query()->sole();
        $second = Tenant::factory()->create(['name' => 'Hotel B OAuth']);

        TenantDomain::query()->create(['tenant_id' => $first->id, 'domain' => 'hotel-a.test']);
        TenantDomain::query()->create(['tenant_id' => $second->id, 'domain' => 'hotel-b.test']);
        app(TenantRoleService::class)->provision($first);
        app(TenantRoleService::class)->provision($second);

        $user = User::factory()->create(['current_tenant_id' => $first->id]);
        $user->tenants()->syncWithoutDetaching([
            $first->id => ['is_active' => true, 'is_owner' => true],
            $second->id => ['is_active' => true, 'is_owner' => true],
        ]);
        app(TenantContext::class)->run($second, fn () => $user->unsetRelation('roles')->assignRole('admin'));

        return [$first, $second, $user];
    }

    /** @param string|list<string> $redirectUris */
    private function registerOAuthClient(string|array $redirectUris): string
    {
        return (string) $this->postJson('/oauth/register', [
            'client_name' => 'PKCE integration client',
            'redirect_uris' => is_array($redirectUris) ? $redirectUris : [$redirectUris],
        ])->assertCreated()->json('client_id');
    }

    private function configureControlPanelHost(): void
    {
        config([
            'lora.control_panel_url' => 'https://admin.lorapms.test',
            'lora.control_panel_hosts' => ['admin.lorapms.test'],
            'lora.dedicated_control_panel_hosts' => ['admin.lorapms.test'],
        ]);
    }

    private function authorizationUrl(
        string $host,
        string $clientId,
        string $redirectUri,
        string $verifier,
        string $state,
    ): string {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return 'https://'.$host.'/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'mcp:use offline_access',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'consent',
        ]);
    }

    private function authorizeClientOnHost(
        User $user,
        string $host,
        string $clientId,
        string $redirectUri,
        string $verifier,
        string $state,
    ): string {
        $consent = $this->actingAs($user)->get($this->authorizationUrl(
            $host,
            $clientId,
            $redirectUri,
            $verifier,
            $state,
        ));
        $consent->assertOk();

        return $this->approveAuthorization(
            $host,
            $clientId,
            $state,
            (string) session('authToken'),
        );
    }

    private function approveAuthorization(
        string $host,
        string $clientId,
        string $state,
        string $authToken,
    ): string {
        $response = $this->post('https://'.$host.'/oauth/authorize', [
            'client_id' => $clientId,
            'state' => $state,
            'auth_token' => $authToken,
        ])->assertRedirect();

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame($state, $query['state'] ?? null);
        $this->assertIsString($query['code'] ?? null);

        return $query['code'];
    }

    private function exchangeAuthorizationCode(
        string $host,
        string $clientId,
        string $redirectUri,
        string $code,
        string $verifier,
    ) {
        return $this->postJson('https://'.$host.'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $verifier,
        ]);
    }

    private function refreshAccessToken(string $host, string $clientId, string $refreshToken)
    {
        return $this->postJson('https://'.$host.'/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
            'scope' => 'mcp:use offline_access',
        ]);
    }
}
