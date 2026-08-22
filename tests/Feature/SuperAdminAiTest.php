<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Truri AI i platformës në super-admin (task #407): NJË çelës qendror Gemini
 * për të gjithë hotelet — vendoset/hiqet vetëm këtu, kurrë nga një tenant.
 */
class SuperAdminAiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'lora.control_panel_hosts' => ['admin.lorapms.test'],
            'lora.dedicated_control_panel_hosts' => ['admin.lorapms.test'],
            'services.gemini.key' => null,
        ]);
    }

    public function test_super_admin_saves_the_central_key_and_stale_alarm_clears(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        // Çelësi i vjetër u shënua i prishur nga kontrolli ditor…
        PlatformSetting::set('ai.gemini_key_health', ['ok' => false, 'error' => 'i prishur'], 'json');

        $this->actingAs($admin)
            ->put('https://admin.lorapms.test/super-admin/ai', ['gemini_key' => '  central-key-123  '])
            ->assertRedirect();

        // …çelësi i RI ruhet i pastruar dhe alarmi i të vjetrit hiqet (Codex #511).
        $this->assertSame('central-key-123', PlatformSetting::get('ai.gemini_key'));
        $this->assertSame('', PlatformSetting::get('ai.gemini_key_health'));
    }

    public function test_super_admin_clears_the_key_and_the_page_never_ships_it_raw(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        PlatformSetting::set('ai.gemini_key', 'central-key-123', 'text');
        PlatformSetting::set('ai.gemini_key_health', ['ok' => true], 'json');

        // Faqja tregon vetëm gjurmën e maskuar — kurrë çelësin e plotë.
        $this->actingAs($admin)
            ->get('https://admin.lorapms.test/super-admin/ai')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai.configured', true)
                ->where('ai.key_hint', '••••••-123')
                ->missing('ai.key'))
            ->assertDontSee('central-key-123');

        $this->actingAs($admin)
            ->put('https://admin.lorapms.test/super-admin/ai', ['clear_key' => true])
            ->assertRedirect();

        $this->assertSame('', PlatformSetting::get('ai.gemini_key'));
        $this->assertSame('', PlatformSetting::get('ai.gemini_key_health'));
    }

    public function test_regular_tenant_admin_cannot_touch_the_central_key(): void
    {
        $tenant = Tenant::query()->sole();
        $user = User::factory()->create(['is_super_admin' => false, 'current_tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->put('https://admin.lorapms.test/super-admin/ai', ['gemini_key' => 'evil-key'])
            ->assertForbidden();

        $this->assertNull(PlatformSetting::get('ai.gemini_key'));
    }
}
