<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kontrolli ditor i shëndetit të çelësit QENDROR Gemini (task #382; GLOBAL
 * nga task #407): NJË çelës platforme (PlatformSetting, si çelësi i kursit
 * të këmbimit) shërben çdo hotel — një thirrje metadata pothuaj-falas e
 * verifikon dhe panelet paralajmërojnë PARA se Lora të heshtë me mysafirë.
 */
class GeminiKeyHealthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);

        // Vetëm PlatformSetting vendos — jo çelësi i env-it të dev-it.
        config()->set('services.gemini.key', null);
        config()->set('services.gemini.model', 'gemini-test-model');
        config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /** Fake që gjykon NGA ÇELËSI i kërkesës — si Google reale. */
    private function fakeGoogleByKey(): void
    {
        Http::fake(function ($request) {
            return match ($request->header('x-goog-api-key')[0] ?? '') {
                'good-key' => Http::response(['name' => 'models/gemini-test-model'], 200),
                'bad-key' => Http::response(['error' => ['code' => 400, 'message' => 'API key not valid']], 400),
                default => Http::response('', 429),
            };
        });
    }

    /** Task #407: çelësi zgjidhet VETËM globalisht — Setting-u per-tenant i mbetur në DB shpërfillet. */
    public function test_key_resolution_is_platform_only_and_ignores_tenant_settings(): void
    {
        // Mbetje e epokës per-tenant në DB — s'duhet të ketë më fuqi.
        Setting::set('ai.gemini_key', 'stale-tenant-key', 'text');

        $this->assertNull(app(GeminiClient::class)->key());
        $this->assertFalse(app(GeminiClient::class)->configured());

        // Env-i si rrugë rezervë…
        config()->set('services.gemini.key', 'env-key');
        $this->assertSame('env-key', app(GeminiClient::class)->key());

        // …dhe PlatformSetting fiton mbi env-in.
        PlatformSetting::set('ai.gemini_key', 'platform-key', 'text');
        $this->assertSame('platform-key', app(GeminiClient::class)->key());
    }

    /** Codex #560: edhe MODELI zgjidhet vetëm globalisht — kontrolli global verifikon saktësisht atë që përdoret. */
    public function test_model_resolution_is_platform_only_and_ignores_tenant_settings(): void
    {
        Setting::set('ai.gemini_model', 'tenant-old-model', 'text');

        $this->assertSame('gemini-test-model', app(GeminiClient::class)->model());
    }

    public function test_command_records_platform_health_ok_and_invalid(): void
    {
        $this->fakeGoogleByKey();

        PlatformSetting::set('ai.gemini_key', 'good-key', 'text');
        $this->artisan('gemini:check-key')->assertSuccessful();

        $health = PlatformSetting::get('ai.gemini_key_health');
        $this->assertTrue($health['ok']);
        $this->assertSame(hash('sha256', 'good-key'), $health['key_fp']);

        PlatformSetting::set('ai.gemini_key', 'bad-key', 'text');
        $this->artisan('gemini:check-key')->assertSuccessful();

        $health = PlatformSetting::get('ai.gemini_key_health');
        $this->assertFalse($health['ok']);
        $this->assertStringContainsString('400', $health['error']);
    }

    public function test_no_key_means_no_call_and_no_warning(): void
    {
        Http::fake();
        // Alarm i vjetruar nga një çelës i hequr — duhet të pastrohet.
        PlatformSetting::set('ai.gemini_key_health', ['ok' => false, 'error' => 'i vjetër'], 'json');

        $this->artisan('gemini:check-key')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('', PlatformSetting::get('ai.gemini_key_health'));
    }

    public function test_transient_429_keeps_the_previous_state(): void
    {
        $this->fakeGoogleByKey();
        PlatformSetting::set('ai.gemini_key', 'unknown-key-gets-429', 'text');
        PlatformSetting::set('ai.gemini_key_health', ['ok' => true, 'checked_at' => '2026-08-20T06:30:00+02:00', 'error' => null], 'json');

        $this->artisan('gemini:check-key')->assertSuccessful();

        // 429 kalimtare NUK e shënon çelësin të prishur.
        $this->assertTrue(PlatformSetting::get('ai.gemini_key_health')['ok']);
    }

    public function test_a_key_changed_mid_check_discards_the_stale_result(): void
    {
        // Kërkesa HTTP "në fluturim" — pikërisht atëherë super-admini ndërron
        // çelësin: rezultati i çelësit të VJETËR duhet hedhur poshtë (Codex #512).
        Http::fake(function () {
            PlatformSetting::set('ai.gemini_key', 'brand-new-key', 'text');

            return Http::response(['error' => ['code' => 400, 'message' => 'API key not valid']], 400);
        });
        PlatformSetting::set('ai.gemini_key', 'old-broken-key', 'text');

        $this->artisan('gemini:check-key')->assertSuccessful();

        // Asnjë gjendje s'u shkrua nga rezultati i vjetruar.
        $this->assertNull(PlatformSetting::get('ai.gemini_key_health'));
    }

    public function test_a_late_written_result_for_an_old_key_never_reaches_the_panel(): void
    {
        app(TenantRoleService::class)->provision($this->tenant);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        // Rezultat i vjetruar që "fitoi" garën e shkrimit pas ndërrimit të
        // çelësit — gjurma s'përputhet me çelësin aktual → paneli s'e sheh.
        PlatformSetting::set('ai.gemini_key', 'brand-new-key', 'text');
        PlatformSetting::set('ai.gemini_key_health', ['ok' => false, 'checked_at' => now()->toIso8601String(), 'error' => 'i vjetruar', 'key_fp' => hash('sha256', 'old-broken-key')], 'json');

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->get(route('lora-ai.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('geminiKeyHealth', null));
    }

    public function test_panel_warns_only_when_the_stored_health_is_broken(): void
    {
        app(TenantRoleService::class)->provision($this->tenant);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        PlatformSetting::set('ai.gemini_key', 'broken-key', 'text');
        PlatformSetting::set('ai.gemini_key_health', ['ok' => false, 'checked_at' => now()->toIso8601String(), 'error' => 'Çelësi qendror AI u refuzua nga Google (400).', 'key_fp' => hash('sha256', 'broken-key')], 'json');

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->get(route('lora-ai.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('geminiKeyHealth.error', 'Çelësi qendror AI u refuzua nga Google (400).'));

        // Çelës në rregull → asnjë paralajmërim.
        PlatformSetting::set('ai.gemini_key_health', ['ok' => true, 'checked_at' => now()->toIso8601String(), 'error' => null, 'key_fp' => hash('sha256', 'broken-key')], 'json');

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->get(route('lora-ai.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('geminiKeyHealth', null));
    }

    /** Task #407: updateAi i tenantit SHPËRFILL çdo çelës të dërguar — ruan vetëm kontekstin e hotelit. */
    public function test_tenant_update_ai_ignores_any_submitted_key(): void
    {
        app(TenantRoleService::class)->provision($this->tenant);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->put(route('settings.ai'), ['gemini_key' => 'sneaky-key', 'hotel_context' => 'Hotel buzë detit me 12 dhoma.'])
            ->assertRedirect();

        // Çelësi s'u shkrua ASKUND; konteksti u ruajt normalisht.
        $this->assertNull(Setting::get('ai.gemini_key'));
        $this->assertNull(PlatformSetting::get('ai.gemini_key'));
        $this->assertSame('Hotel buzë detit me 12 dhoma.', Setting::get('ai.hotel_context'));
    }
}
