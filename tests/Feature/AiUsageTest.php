<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiGuestReply;
use App\Models\AiUsageEvent;
use App\Models\HotelFaq;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\ChannexClient;
use App\Services\GeminiClient;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Matja e përdorimit AI per-tenant (task #409): çdo thirrje AI → një rresht
 * ai_usage_events me tokenat dhe koston (mikro-USD integer), koeficienti i
 * faturimit i super-adminit nxjerr vlerën e pagesës. Matja është FAIL-SAFE:
 * kurrë s'e prish përgjigjen e mysafirit.
 */
class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);

        config()->set('lora.control_panel_hosts', ['admin.lorapms.test']);
        config()->set('lora.dedicated_control_panel_hosts', ['admin.lorapms.test']);
        config()->set('services.gemini.model', 'gemini-3.7-flash');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function makeThreadWithGuestMessage(): array
    {
        $thread = MessageThread::create([
            'channex_thread_id' => 'thr-'.uniqid(),
            'channel' => 'booking.com',
            'guest_name' => 'Guest Test',
            'status' => 'open',
        ]);
        $message = $thread->messages()->create([
            'sender' => Message::SENDER_GUEST,
            'body' => 'What time is breakfast?',
            'sent_at' => now(),
        ]);

        return [$thread, $message];
    }

    private function fakeGeminiWithUsage(): void
    {
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturn([
                'args' => ['confident' => true, 'reply' => 'Breakfast is 7-10.', 'kind' => 'informative'],
                'toolsUsed' => [],
                'usage' => ['input' => 10_000, 'output' => 500, 'thinking' => 100, 'provider' => 'gemini', 'model' => 'gemini-3.7-flash'],
            ]);
        });
    }

    /** Kriteri 0: përgjigja e bisedës regjistron SAKTËSISHT një rresht me tokenat dhe koston nga çmimorja. */
    public function test_guest_reply_records_exactly_one_usage_row_with_config_pricing(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();
        $this->fakeGeminiWithUsage();
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        $event = AiUsageEvent::query()->sole();
        $this->assertSame($this->tenant->id, $event->tenant_id);
        $this->assertSame('gemini', $event->provider);
        $this->assertSame('gemini-3.7-flash', $event->model);
        $this->assertSame('guest_reply', $event->feature);
        $this->assertSame(10_000, $event->input_tokens);
        $this->assertSame(500, $event->output_tokens);
        $this->assertSame(100, $event->thinking_tokens);
        // Çmimorja config: 10000×0.75 + (500+100)×3.75 = 7500 + 2250 = 9750 mikro-USD.
        $this->assertSame(9750, $event->cost_micro_usd);
        $this->assertSame($thread->id, $event->message_thread_id);
        $this->assertSame($message->id, $event->message_id);
    }

    /** Kriteri 0: edhe thirrjet e STRUKTURUARA (asistenti i çmimeve, votat) maten — nga vetë klienti. */
    public function test_structured_call_records_a_usage_row(): void
    {
        config()->set('services.gemini.key', null);
        PlatformSetting::set('ai.gemini_key', 'test-key', 'text');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'classify', 'args' => ['small_talk' => true]],
                ]]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 5],
            ]),
        ]);

        app(GeminiClient::class)->structured('SYSTEM', 'Pershendetje!', [
            'name' => 'classify',
            'input_schema' => ['type' => 'object', 'properties' => ['small_talk' => ['type' => 'boolean']]],
        ], 'classify', 128, 15);

        $event = AiUsageEvent::query()->sole();
        $this->assertSame('structured', $event->feature);
        $this->assertSame(200, $event->input_tokens);
        // 200×0.75 + (10+5)×3.75 = 150 + 56.25 → 206 mikro-USD (round).
        $this->assertSame(206, $event->cost_micro_usd);
    }

    /** KRITIK (kriteri 1): rreshtat mbajnë GJITHMONË tenant_id-në e bisedës — zero rrjedhje ndër-tenant. */
    public function test_usage_rows_always_carry_the_conversations_tenant(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();
        $this->fakeGeminiWithUsage();
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());
        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        // Tenant i DYTË me rresht të vetin, i shkruar nën kontekstin e vet.
        $other = Tenant::factory()->create();
        app(TenantContext::class)->set($other);
        AiUsageEvent::create(['provider' => 'gemini', 'model' => 'gemini-3.7-flash', 'feature' => 'guest_reply', 'input_tokens' => 1, 'cost_micro_usd' => 1]);
        app(TenantContext::class)->set($this->tenant);

        // Nën kontekstin e tenant-it A shihet VETËM rreshti i tij (scope).
        $this->assertSame(1, AiUsageEvent::query()->count());
        $this->assertSame($this->tenant->id, AiUsageEvent::query()->sole()->tenant_id);

        // Raporti i super-adminit i atribuon secilit të vetin — kurrë përzierje.
        $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);
        app(TenantContext::class)->clear();
        $this->actingAs($admin)
            ->get('https://admin.lorapms.test/super-admin/ai/usage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.tenant_id', $this->tenant->id)
                ->where('rows.0.calls', 1)
                ->where('rows.1.tenant_id', $other->id)
                ->where('rows.1.calls', 1));
        app(TenantContext::class)->set($this->tenant);
    }

    /** Kriteri 2: dështimi i matjes NUK e prish përgjigjen e mysafirit (fail-safe). */
    public function test_metering_failure_never_breaks_the_reply(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();
        $this->fakeGeminiWithUsage();
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        // Shkrimi i matjes hidhet në erë (pa DDL — MySQL-i i CI-së bën commit
        // implicit në DDL dhe prish transaksionin e RefreshDatabase).
        AiUsageEvent::saving(function (): void {
            throw new \RuntimeException('matja u prish me qëllim');
        });

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        // Përgjigja u dërgua njësoj — matja dështoi në heshtje të raportuar.
        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Kriteri 3: koeficienti editohet në super-admin; ndryshon E FATURUESHMEN, kurrë koston reale. */
    public function test_coefficient_changes_billable_but_never_the_real_cost(): void
    {
        AiUsageEvent::create(['provider' => 'gemini', 'model' => 'gemini-3.7-flash', 'feature' => 'guest_reply', 'input_tokens' => 1_000_000, 'cost_micro_usd' => 750_000]);
        $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);
        app(TenantContext::class)->clear();

        $this->actingAs($admin)
            ->put('https://admin.lorapms.test/super-admin/ai', ['billing_coefficient' => 1.5])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get('https://admin.lorapms.test/super-admin/ai/usage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('coefficient', 1.5)
                ->where('totals.cost_usd', 0.75)
                ->where('totals.billable_usd', 1.125));
        app(TenantContext::class)->set($this->tenant);
    }

    /** Kriteri 4: raporti tregon per-tenant thirrjet, tokenat, koston dhe të faturueshmen. */
    public function test_report_shows_per_tenant_usage(): void
    {
        AiUsageEvent::create(['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'feature' => 'guest_reply', 'input_tokens' => 2_000_000, 'output_tokens' => 500_000, 'cost_micro_usd' => 1_000_000]);
        $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);
        app(TenantContext::class)->clear();

        $this->actingAs($admin)
            ->get('https://admin.lorapms.test/super-admin/ai/usage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.tenant', $this->tenant->name)
                ->where('rows.0.input_tokens', 2000000)
                ->where('rows.0.cost_usd', 1)
                ->where('rows.0.models.0.model', 'gpt-5.6-luna')
                ->where('totals.calls', 1));
        app(TenantContext::class)->set($this->tenant);
    }

    /** Çmimorja: mbivendosja e super-adminit fiton mbi config-un për matjet e REJA. */
    public function test_pricing_override_wins_over_config_for_new_meterings(): void
    {
        $recorder = app(\App\Services\AiUsageRecorder::class);
        $this->assertSame(9750, $recorder->costMicroUsd(['input' => 10_000, 'output' => 500, 'thinking' => 100, 'model' => 'gemini-3.7-flash']));

        PlatformSetting::set('ai.pricing_overrides', ['gemini-3.7-flash' => ['input' => 1.50, 'output' => 7.50]], 'json');

        // 10000×1.5 + 600×7.5 = 15000 + 4500 = 19500 mikro-USD.
        $this->assertSame(19_500, $recorder->costMicroUsd(['input' => 10_000, 'output' => 500, 'thinking' => 100, 'model' => 'gemini-3.7-flash']));
    }

    public function test_regular_admin_cannot_open_the_usage_report(): void
    {
        $user = \App\Models\User::factory()->create(['is_super_admin' => false, 'current_tenant_id' => $this->tenant->id]);
        app(TenantContext::class)->clear();

        $this->actingAs($user)
            ->get('https://admin.lorapms.test/super-admin/ai/usage')
            ->assertForbidden();
        app(TenantContext::class)->set($this->tenant);
    }
}
