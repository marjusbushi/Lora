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
