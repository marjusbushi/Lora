<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ridizajni i /pms/lora-ai (task #402, gjetjet Codex PR #542):
 * 1) fikja e një shkalle kaskadon FIKJEN e vlerave të varura edhe në server —
 *    job-et (GenerateAiGuestReply) lexojnë çelësin e vet direkt, ndaj një vlerë
 *    e mbetur ndezur do t'i mbante aktive pas fikjes së nivelit poshtë saj;
 * 2) të ardhurat e kartelës jepen vetëm kujt sheh paratë (view_financials ose
 *    view_finance) — rruga mbrohet vetëm me view_settings.
 */
class LoraAiSettingsCascadeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function basePayload(): array
    {
        return [
            'reservations_enabled' => true,
            'messages_enabled' => true,
            'guest_reply_enabled' => true,
            'guest_auto_reply_enabled' => true,
            'whatsapp_auto_reply_enabled' => true,
            'whatsapp_booking_enabled' => true,
            'pricing_enabled' => true,
            'ai_price_recommendations_enabled' => true,
            'price_apply_enabled' => true,
        ];
    }

    public function test_turning_messages_off_cascades_the_whole_reply_chain_off(): void
    {
        $this->actingAs($this->admin())->put(route('lora-ai.update'), array_merge($this->basePayload(), [
            'messages_enabled' => false,
            // Klienti (i vjetër ose dashakeq) i lë të varurat ndezur — serveri i fik vetë.
            'guest_reply_enabled' => true,
            'guest_auto_reply_enabled' => true,
            'whatsapp_auto_reply_enabled' => true,
            'whatsapp_booking_enabled' => true,
        ]))->assertRedirect();

        $this->assertFalse((bool) Setting::get('ai_mcp.guest_reply_enabled'));
        $this->assertFalse((bool) Setting::get('ai_mcp.guest_auto_reply_enabled'));
        $this->assertFalse((bool) Setting::get('ai_mcp.whatsapp_auto_reply_enabled'));
        $this->assertFalse((bool) Setting::get('ai_mcp.whatsapp_booking_enabled'));
    }

    public function test_turning_whatsapp_off_cascades_only_booking_off(): void
    {
        $this->actingAs($this->admin())->put(route('lora-ai.update'), array_merge($this->basePayload(), [
            'whatsapp_auto_reply_enabled' => false,
            'whatsapp_booking_enabled' => true,
        ]))->assertRedirect();

        $this->assertTrue((bool) Setting::get('ai_mcp.guest_reply_enabled'));
        $this->assertTrue((bool) Setting::get('ai_mcp.guest_auto_reply_enabled'));
        $this->assertFalse((bool) Setting::get('ai_mcp.whatsapp_booking_enabled'));
    }

    public function test_turning_pricing_off_cascades_price_actions_off(): void
    {
        $this->actingAs($this->admin())->put(route('lora-ai.update'), array_merge($this->basePayload(), [
            'pricing_enabled' => false,
            'price_apply_enabled' => true,
            'ai_price_recommendations_enabled' => true,
        ]))->assertRedirect();

        $this->assertFalse((bool) Setting::get('ai_mcp.price_apply_enabled'));
        $this->assertFalse((bool) Setting::get('ai_mcp.ai_price_recommendations_enabled'));
    }

    public function test_partial_payload_cannot_enable_booking_over_a_stored_whatsapp_parent_that_is_off(): void
    {
        Setting::set('ai_mcp.whatsapp_auto_reply_enabled', false, 'boolean');

        // Payload i pjesshëm: prindi mungon fare, fëmija vjen i ndezur —
        // normalizimi duhet të konsultojë vlerën e RUAJTUR të prindit.
        $this->actingAs($this->admin())->put(route('lora-ai.update'), [
            'reservations_enabled' => true,
            'messages_enabled' => true,
            'guest_reply_enabled' => true,
            'pricing_enabled' => true,
            'price_apply_enabled' => false,
            'whatsapp_booking_enabled' => true,
        ])->assertRedirect();

        $this->assertFalse((bool) Setting::get('ai_mcp.whatsapp_booking_enabled'));
    }

    public function test_booking_revenue_sums_the_frozen_base_snapshot_of_the_reservations(): void
    {
        $type = \App\Models\RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $room = \App\Models\Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = \App\Models\Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@test.local', 'phone' => '+355 69 000 0000']);
        $admin = $this->admin();
        $reservation = \App\Models\Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 160, 'adults' => 2,
        ]);
        \Illuminate\Support\Facades\DB::table('audit_logs')->insert([
            'tenant_id' => $this->tenant->id, 'source' => 'ai',
            'action' => 'message.ai_booking_confirmed',
            'subject_type' => \App\Models\Reservation::class, 'subject_id' => $reservation->id,
            'properties' => json_encode(['total' => 999999]), // qëllimisht ndryshe — s'duhet lexuar më
            'created_at' => now(),
        ]);

        $expected = round((float) $reservation->fresh()->total_amount_base, 2);

        $this->actingAs($admin)->get(route('lora-ai.index'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.bookings', 1)
                ->where('stats.bookingRevenue', fn ($value) => abs((float) $value - $expected) < 0.005));
    }

    public function test_booking_revenue_is_hidden_from_roles_without_money_permissions(): void
    {
        Permission::findOrCreate('view_settings', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $viewer = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $viewer->givePermissionTo('view_settings');

        $this->actingAs($viewer)->get(route('lora-ai.index'))
            ->assertInertia(fn ($page) => $page
                ->component('LoraAi/Index')
                ->where('stats.bookingRevenue', null));
    }

    public function test_booking_revenue_is_visible_to_admins_with_money_permissions(): void
    {
        $this->actingAs($this->admin())->get(route('lora-ai.index'))
            ->assertInertia(fn ($page) => $page
                ->component('LoraAi/Index')
                ->where('stats.bookingRevenue', 0));
    }
}
