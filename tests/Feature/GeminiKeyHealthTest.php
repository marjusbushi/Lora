<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kontrolli ditor i shëndetit të çelësit Gemini per-hotel (task #382):
 * thirrje metadata pothuaj-falas → gjendja per-tenant → paralajmërim në panel
 * PARA se Lora të heshtë me mysafirë realë.
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

        // Vetëm Setting-u per-tenant vendos — jo çelësi i env-it të dev-it.
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

    public function test_command_records_health_per_tenant_ok_and_invalid(): void
    {
        $this->fakeGoogleByKey();

        // Tenant-i A me çelës të mirë…
        Setting::set('ai.gemini_key', 'good-key', 'string');

        // …tenant-i B me çelës të pavlefshëm.
        $other = Tenant::factory()->create();
        app(TenantContext::class)->set($other);
        Setting::set('ai.gemini_key', 'bad-key', 'string');

        app(TenantContext::class)->set($this->tenant);
        $this->artisan('gemini:check-key', ['--tenant' => $this->tenant->id])->assertSuccessful();

        app(TenantContext::class)->set($other);
        $this->artisan('gemini:check-key', ['--tenant' => $other->id])->assertSuccessful();

        // Gjendjet janë PER-TENANT, të ndara dhe të sakta.
        app(TenantContext::class)->set($this->tenant);
        $healthA = Setting::get('ai.gemini_key_health');
        $this->assertTrue($healthA['ok']);

        app(TenantContext::class)->set($other);
        $healthB = Setting::get('ai.gemini_key_health');
        $this->assertFalse($healthB['ok']);
        $this->assertStringContainsString('400', $healthB['error']);
    }

    public function test_no_key_means_no_call_and_no_warning(): void
    {
        Http::fake();
        // Alarm i vjetruar nga një çelës i hequr — duhet të pastrohet.
        Setting::set('ai.gemini_key_health', ['ok' => false, 'error' => 'i vjetër'], 'json');

        $this->artisan('gemini:check-key', ['--tenant' => $this->tenant->id])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('', Setting::get('ai.gemini_key_health'));
    }

    public function test_transient_429_keeps_the_previous_state(): void
    {
        $this->fakeGoogleByKey();
        Setting::set('ai.gemini_key', 'unknown-key-gets-429', 'string');
        Setting::set('ai.gemini_key_health', ['ok' => true, 'checked_at' => '2026-08-20T06:30:00+02:00', 'error' => null], 'json');

        $this->artisan('gemini:check-key', ['--tenant' => $this->tenant->id])->assertSuccessful();

        // 429 kalimtare NUK e shënon çelësin të prishur.
        $this->assertTrue(Setting::get('ai.gemini_key_health')['ok']);
    }

    public function test_a_key_changed_mid_check_discards_the_stale_result(): void
    {
        // Kërkesa HTTP "në fluturim" — pikërisht atëherë admini ndërron çelësin:
        // rezultati i çelësit të VJETËR duhet hedhur poshtë (Codex #512).
        Http::fake(function () {
            Setting::set('ai.gemini_key', 'brand-new-key', 'string');

            return Http::response(['error' => ['code' => 400, 'message' => 'API key not valid']], 400);
        });
        Setting::set('ai.gemini_key', 'old-broken-key', 'string');

        $this->artisan('gemini:check-key', ['--tenant' => $this->tenant->id])->assertSuccessful();

        // Asnjë gjendje s'u shkrua nga rezultati i vjetruar.
        $this->assertNull(Setting::get('ai.gemini_key_health'));
    }

    public function test_saving_a_new_key_clears_the_stale_broken_alarm(): void
    {
        app(TenantRoleService::class)->provision($this->tenant);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        // Çelësi i vjetër u shënua i prishur nga kontrolli ditor…
        Setting::set('ai.gemini_key_health', ['ok' => false, 'checked_at' => now()->toIso8601String(), 'error' => 'i prishur'], 'json');

        // …admini vendos çelës të RI: alarmi i të vjetrit s'vlen më (Codex #511).
        $this->actingAs($admin)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->put(route('settings.ai'), ['gemini_key' => 'brand-new-key'])
            ->assertRedirect();

        $this->assertSame('', Setting::get('ai.gemini_key_health'));
    }

    public function test_panel_warns_only_when_the_stored_health_is_broken(): void
    {
        app(TenantRoleService::class)->provision($this->tenant);
        $admin = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        Setting::set('ai.gemini_key_health', ['ok' => false, 'checked_at' => now()->toIso8601String(), 'error' => 'Çelësi u refuzua nga Google (400).'], 'json');

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->get(route('lora-ai.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('geminiKeyHealth.error', 'Çelësi u refuzua nga Google (400).'));

        // Çelës në rregull → asnjë paralajmërim.
        Setting::set('ai.gemini_key_health', ['ok' => true, 'checked_at' => now()->toIso8601String(), 'error' => null], 'json');

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->get(route('lora-ai.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('geminiKeyHealth', null));
    }
}
