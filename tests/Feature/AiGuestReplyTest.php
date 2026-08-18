<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiGuestReply;
use App\Models\HotelFaq;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\ChannexClient;
use App\Services\GeminiClient;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AiGuestReplyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Ritmi njerëzor (task #368) mos i bëjë testet të flenë realisht.
        Sleep::fake();

        // Tenant-i legacy nga migrimet — ka messages të trashëguar (falas) + CM.
        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
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

    /** @param  array<int,string>  $toolsUsed  simulon cilat mjete "përdori" Gemini në këtë përgjigje */
    private function fakeGemini(bool $confident, string $reply = 'Breakfast is 7-10.', array $toolsUsed = [], ?string $kind = null): void
    {
        $this->mock(GeminiClient::class, function ($mock) use ($confident, $reply, $toolsUsed, $kind) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')
                ->andReturn([
                    'args' => ['confident' => $confident, 'reply' => $reply] + ($kind ? ['kind' => $kind] : []),
                    'toolsUsed' => $toolsUsed,
                ]);
        });
    }

    private function runJob(MessageThread $thread, Message $message): void
    {
        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);
    }

    public function test_confident_with_faq_and_auto_on_sends_labeled_ai_reply(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10 every day.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, function ($mock) use ($thread) {
            $mock->shouldReceive('sendThreadMessage')->once()
                ->with($thread->channex_thread_id, 'Breakfast is 7-10.');
        });

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertNull($thread->ai_suggestion);
        $this->assertDatabaseHas('messages', [
            'message_thread_id' => $thread->id,
            'sender' => Message::SENDER_HOST,
            'sent_by_ai' => true,
            'body' => 'Breakfast is 7-10.',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message.ai_reply']);
    }

    public function test_unconfident_leaves_draft_only(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(false, 'Recepsioni do t\'ju përgjigjet shumë shpejt.');
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertSame('Recepsioni do t\'ju përgjigjet shumë shpejt.', $thread->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    public function test_empty_faq_never_auto_sends_even_when_confident(): void
    {
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
    }

    public function test_auto_off_leaves_draft_only(): void
    {
        Setting::set('ai_mcp.guest_auto_reply_enabled', false, 'boolean');
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
    }

    public function test_guest_reply_disabled_is_full_noop(): void
    {
        Setting::set('ai_mcp.guest_reply_enabled', false, 'boolean');
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('structured')->never());
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNull($thread->refresh()->ai_suggestion);
    }

    public function test_staff_already_replied_is_noop(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();
        $thread->messages()->create([
            'sender' => Message::SENDER_HOST,
            'body' => 'Staff answered already.',
            'sent_at' => now()->addSecond(),
        ]);

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNull($thread->refresh()->ai_suggestion);
    }

    /** Task #375: limiti u ngrit 5→15 — e 15-ta kalon, e 16-ta ndalon. */
    public function test_fifteenth_reply_sends_but_sixteenth_is_rate_limited(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        Cache::put(sprintf('ai-guest-reply:%d:%d', $this->tenant->id, $thread->id), 14, now()->addHour());

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->runJob($thread, $message);

        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    public function test_rate_limit_stops_the_sixteenth_reply_in_an_hour(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        Cache::put(sprintf('ai-guest-reply:%d:%d', $this->tenant->id, $thread->id), 15, now()->addHour());

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNull($thread->refresh()->ai_suggestion);
    }

    public function test_race_staff_reply_during_gemini_flight_prevents_send_and_draft(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        // Gemini "vonon" — dhe ndërkohë stafi përgjigjet.
        $this->mock(GeminiClient::class, function ($mock) use ($thread) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function () use ($thread) {
                $thread->messages()->create([
                    'sender' => Message::SENDER_HOST,
                    'body' => 'Staff replied mid-flight',
                    'sent_at' => now()->addSecond(),
                ]);

                return ['args' => ['confident' => true, 'reply' => 'Late AI answer'], 'toolsUsed' => []];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    public function test_auto_reply_stores_channex_message_id_for_dedup(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once()->andReturn(['id' => 'chx-msg-123']));

        $this->runJob($thread, $message);

        $this->assertDatabaseHas('messages', [
            'message_thread_id' => $thread->id,
            'sent_by_ai' => true,
            'channex_message_id' => 'chx-msg-123',
        ]);
    }

    /**
     * Kriteri #1 i task #363: mysafiri jep datat → mjeti check_availability
     * EKZEKUTOHET vërtet (executor-i real i job-it) → përgjigja e dërguar mbart
     * çmimin e motorit real — dhe dërgohet edhe me 0 FAQ (tool-grounded).
     */
    public function test_availability_question_runs_engine_and_sends_real_prices(): void
    {
        $type = \App\Models\RoomType::create(['name' => 'Dhomë Deluxe', 'base_price' => 100, 'max_occupancy' => 2]);
        \App\Models\Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        // Mock-u luan rolin e Gemini-t: thërret executor-in REAL që i jep job-i,
        // dhe ndërton përgjigjen nga numrat që kthen motori — si modeli i vërtetë.
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $quote = $executors['check_availability'](['check_in' => '2027-07-01', 'check_out' => '2027-07-04', 'adults' => 2]);
                $room = $quote['room_types'][0];

                return [
                    'args' => [
                        'confident' => true,
                        'reply' => "{$room['name']}: {$room['stay_total']} {$quote['currency']} për {$quote['nights']} net.",
                    ],
                    'toolsUsed' => ['check_availability'],
                ];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')
            ->once()->withArgs(fn ($threadId, $body) => str_contains($body, '300')));

        $this->runJob($thread, $message);

        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
        // Tool-grounded + e sigurt → asnjë "pyetje pa përgjigje" për ciklin e FAQ-së.
        $this->assertNull($thread->refresh()->ai_unanswered_question);
    }

    /** Kriteri #2 i task #363: OTA merr çmimin kanonik, WhatsApp finalen me zbritje direkte. */
    public function test_ota_and_whatsapp_channels_price_differently(): void
    {
        Setting::set('pricing_programs.direct_discount_enabled', true, 'boolean');
        Setting::set('pricing_programs.direct_discount_pct', 10);
        $type = \App\Models\RoomType::create(['name' => 'Dhomë Standard', 'base_price' => 100, 'max_occupancy' => 2]);
        \App\Models\Room::create(['room_type_id' => $type->id, 'room_number' => '201', 'floor' => 2, 'status' => 'available']);

        $service = app(\App\Services\GuestStayQuote::class);
        $ota = $service->forGuest('booking.com', '2027-07-01', '2027-07-03', 2);
        $wa = $service->forGuest('whatsapp', '2027-07-01', '2027-07-03', 2);

        // 2 net × 100 = 200 kanonik (= finali që sheh mysafiri në OTA pas Genius).
        $this->assertSame(200.0, $ota['room_types'][0]['stay_total']);
        $this->assertArrayNotHasKey('direct_discount_pct', $ota['room_types'][0]);
        // WhatsApp: finali direkt me -10% = 180, me origjinalin të dukshëm.
        $this->assertSame(180.0, $wa['room_types'][0]['stay_total']);
        $this->assertSame(200.0, $wa['room_types'][0]['price_before_direct_discount']);
        $this->assertSame(10.0, $wa['room_types'][0]['direct_discount_pct']);
    }

    /**
     * Kriteri #3 i task #363: 0 FAQ + tool-grounded → dërgohet. "Grounded" pas
     * gjetjes Codex (PR #462) = kuotë e SUKSESSHME + numrat përputhen me motorin
     * — prandaj mock-u e thërret executor-in real, s'mjafton etiketa toolsUsed.
     */
    public function test_tool_grounded_reply_auto_sends_with_zero_faq(): void
    {
        $type = \App\Models\RoomType::create(['name' => 'Dhomë Deluxe', 'base_price' => 100, 'max_occupancy' => 2]);
        \App\Models\Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $executors['check_availability'](['check_in' => '2027-07-01', 'check_out' => '2027-07-04', 'adults' => 2]);

                return ['args' => ['confident' => true, 'reply' => 'Dhomë Deluxe: 300 EUR për 3 net.'], 'toolsUsed' => ['check_availability']];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertNull($thread->ai_suggestion);
        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Gjetja Codex #1 (PR #462): numër që s'ekziston në motor (350 ≠ 300) → JO grounded → draft, asnjë dërgim. */
    public function test_reply_with_number_not_from_engine_stays_draft(): void
    {
        $type = \App\Models\RoomType::create(['name' => 'Dhomë Deluxe', 'base_price' => 100, 'max_occupancy' => 2]);
        \App\Models\Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $executors['check_availability'](['check_in' => '2027-07-01', 'check_out' => '2027-07-04', 'adults' => 2]);

                // AI "halucinon" një çmim tjetër nga ai i motorit (300).
                return ['args' => ['confident' => true, 'reply' => 'Dhomë Deluxe: 350 EUR për 3 net.'], 'toolsUsed' => ['check_availability']];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Gjetja Codex #471: FAQ e pranishme NUK e anashkalon verifikimin e numrave kur mjetet u përdorën. */
    public function test_faq_presence_does_not_bypass_number_grounding_for_tool_answers(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        $type = \App\Models\RoomType::create(['name' => 'Dhomë Deluxe', 'base_price' => 100, 'max_occupancy' => 2]);
        \App\Models\Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $executors['check_availability'](['check_in' => '2027-07-01', 'check_out' => '2027-07-04', 'adults' => 2]);

                // Çmim i "korrigjuar" nga AI (350 ≠ 300) — FAQ-ja s'duhet ta shpëtojë.
                return ['args' => ['confident' => true, 'reply' => 'Dhomë Deluxe: 350 EUR për 3 net.'], 'toolsUsed' => ['check_availability']];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Gjetja Codex #1 (PR #462): mjeti u thirr por ktheu ERROR (pa kuotë) → etiketa toolsUsed s'mjafton → draft. */
    public function test_failed_quote_is_not_grounded_even_when_tool_was_called(): void
    {
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $result = $executors['check_availability'](['check_in' => '2027-07-04', 'check_out' => '2027-07-01']);
                \PHPUnit\Framework\Assert::assertArrayHasKey('error', $result);

                return ['args' => ['confident' => true, 'reply' => 'Kemi dhoma të lira!'], 'toolsUsed' => ['check_availability']];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
    }

    /** Kriteri #4 i task #363: pa data të plota → pyetje sqaruese, pa mjet e pa çmime — dërgohet si bisedë normale. */
    public function test_missing_dates_reply_asks_for_dates_without_prices(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $ask = 'Me kënaqësi! Për cilat data dhe sa persona?';
        $this->fakeGemini(true, $ask, []);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once()->with($thread->channex_thread_id, $ask));

        $this->runJob($thread, $message);

        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true, 'body' => $ask]);
    }

    /**
     * Task #367: dështimi i Gemini-t duhet të RRËZOJË job-in (që radha ta
     * riprovojë me tries/backoff) — jo të gëlltitet në heshtje pa as draft.
     */
    public function test_gemini_failure_bubbles_up_so_the_queue_retries(): void
    {
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andThrow(new \RuntimeException('Shumë kërkesa te Google (limiti u kalua).'));
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        try {
            $this->runJob($thread, $message);
            $this->fail('Përjashtimi duhej të dilte nga handle() — radha s\'riprovon dot një "sukses".');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('limiti u kalua', $e->getMessage());
        }

        $thread->refresh();
        $this->assertNull($thread->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Task #369: muhabeti i mirësjelljes dërgohet VETË edhe me 0 FAQ — dhe prompt-i mbart identitetin 'Lora'. */
    public function test_small_talk_auto_sends_with_zero_faq_and_prompt_carries_identity(): void
    {
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $capturedSystem = '';
        $this->mock(GeminiClient::class, function ($mock) use (&$capturedSystem) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system) use (&$capturedSystem) {
                $capturedSystem = $system;

                return ['args' => ['confident' => true, 'reply' => 'Përshëndetje! Jam Lora — si mund t\'ju ndihmoj?', 'kind' => 'small_talk'], 'toolsUsed' => []];
            });
            // Vota e dytë e pavarur (gjetje Codex #470) konfirmon muhabetin.
            $mock->shouldReceive('structured')->once()->andReturn(['small_talk' => true]);
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertNull($thread->ai_suggestion);
        $this->assertNull($thread->ai_unanswered_question);
        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
        $this->assertStringContainsString('Lora', $capturedSystem);
    }

    /** Gjetja Codex #470: pyetje faktesh e keq-klasifikuar si muhabet (pa shifra) → vota e dytë e rrëzon → draft. */
    public function test_misclassified_fact_question_fails_second_vote_and_stays_draft(): void
    {
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            // Përgjigjësi gabon: fakt i shpikur pa shifra, i etiketuar si muhabet.
            $mock->shouldReceive('converse')
                ->andReturn(['args' => ['confident' => true, 'reply' => 'Po, parkimi është falas!', 'kind' => 'small_talk'], 'toolsUsed' => []]);
            // Klasifikuesi i pavarur (sheh vetëm mesazhin e mysafirit) e kap.
            $mock->shouldReceive('structured')->once()->andReturn(['small_talk' => false]);
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Task #376: pyetja sqaruese ("cilat data?") dërgohet VETË edhe me 0 FAQ — me votë të dytë mbi përgjigjen. */
    public function test_clarifying_question_auto_sends_with_zero_faq(): void
    {
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')
                ->andReturn(['args' => ['confident' => true, 'reply' => 'Me kënaqësi! Për cilat data dhe sa persona?', 'kind' => 'clarifying'], 'toolsUsed' => []]);
            // Vota e dytë vlerëson PËRGJIGJEN — konfirmon se është vetëm pyetje sqaruese.
            $mock->shouldReceive('structured')->once()->andReturn(['clarifying' => true]);
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertNull($thread->ai_suggestion);
        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Task #376: clarifying me SHIFRA = fakte të kontrabanduara → draft (vota e dytë s'thirret fare). */
    public function test_clarifying_with_digits_stays_draft(): void
    {
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true, 'Cilat data? Kemi çmime nga 80 EUR.', [], 'clarifying');
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
    }

    /** Task #376: vota e dytë e rrëzon (fakt i fshehur pa shifra) → draft. */
    public function test_clarifying_failing_second_vote_stays_draft(): void
    {
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')
                ->andReturn(['args' => ['confident' => true, 'reply' => 'Cilat data? Kemi edhe pishinë të mbuluar!', 'kind' => 'clarifying'], 'toolsUsed' => []]);
            $mock->shouldReceive('structured')->once()->andReturn(['clarifying' => false]);
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertNotNull($thread->ai_suggestion);
        // Gjetja Codex #478: vota e rrëzuar = fakt i fshehur → pyetja e mysafirit
        // HYN te materiali i FAQ-së (verdikti vendos, jo etiketa e papërpunuar).
        $this->assertNotNull($thread->ai_unanswered_question);
    }

    /** Task #376 + Codex #478: auto OFF → vota s'ekzekutohet fare (kursim) dhe pyetja ruhet si material FAQ-je. */
    public function test_clarifying_draft_with_auto_off_skips_vote_and_keeps_faq_material(): void
    {
        Setting::set('ai_mcp.guest_auto_reply_enabled', false, 'boolean');
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        // Mock-u PA 'structured' — po u thirr vota me auto OFF, testi dështon vetë.
        $this->fakeGemini(true, 'Për cilat data dhe sa persona?', [], 'clarifying');
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertNotNull($thread->ai_suggestion);
        $this->assertNotNull($thread->ai_unanswered_question);
    }

    /** Task #369: small_talk me SHIFRA = kontrabandë faktesh → draft, kurrë dërgim. */
    public function test_small_talk_with_digits_stays_draft(): void
    {
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true, 'Jam Lora! Na merrni në 069 123 4567.', [], 'small_talk');
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
    }

    /** Task #369: small_talk me auto OFF → draft, po PA 'pyetje pa përgjigje' (s'ndot sugjerimet e FAQ-së). */
    public function test_small_talk_draft_does_not_create_faq_suggestion_material(): void
    {
        Setting::set('ai_mcp.guest_auto_reply_enabled', false, 'boolean');
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true, 'Përshëndetje! Si mund t\'ju ndihmoj?', [], 'small_talk');
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $thread->refresh();
        $this->assertNotNull($thread->ai_suggestion);
        $this->assertNull($thread->ai_unanswered_question);
    }

    /** Task #371: përgjigja e dërguar e Lora-s transmetohet live (MessageReceived) — stafi s'ka nevojë për refresh. */
    public function test_auto_reply_broadcasts_message_received_for_live_inbox(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\MessageReceived::class]);
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(true);
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->runJob($thread, $message);

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\MessageReceived::class,
            fn ($event) => $event->tenantId === $thread->tenant_id && $event->threadId === $thread->id,
        );
    }

    /** Task #371: draft-i (pa dërgim) s'transmeton asgjë — s'ka mesazh të ri për t'u shfaqur. */
    public function test_draft_path_does_not_broadcast(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\MessageReceived::class]);
        HotelFaq::create(['question' => 'Breakfast?', 'answer' => '7-10.']);
        [$thread, $message] = $this->makeThreadWithGuestMessage();

        $this->fakeGemini(false, 'Recepsioni do t\'ju përgjigjet shumë shpejt.');
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\MessageReceived::class);
    }

    /** Task #370: identiteti/karakteri ruhen si string nga UI dhe emri i ri mbërrin në promptin e job-it. */
    public function test_identity_settings_persist_and_reach_the_prompt(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = \App\Models\User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('lora-ai.update'), [
            'reservations_enabled' => true,
            'messages_enabled' => true,
            'guest_reply_enabled' => true,
            'pricing_enabled' => true,
            'price_apply_enabled' => false,
            'assistant_name' => 'Maja',
            'assistant_character' => 'Serioze, e shkurtër dhe pa emoji.',
        ])->assertRedirect();

        $this->assertSame('Maja', Setting::get('ai_mcp.assistant_name'));
        $this->assertSame('Serioze, e shkurtër dhe pa emoji.', Setting::get('ai_mcp.assistant_character'));

        // Kërkesa HTTP e pastron TenantContext në terminim — rikthe kontekstin
        // që job-i (TenantAwareJob) të mos dalë te roja e parë me tenant null.
        app(TenantContext::class)->set($this->tenant);

        [$thread, $message] = $this->makeThreadWithGuestMessage();
        $capturedSystem = '';
        $this->mock(GeminiClient::class, function ($mock) use (&$capturedSystem) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system) use (&$capturedSystem) {
                $capturedSystem = $system;

                return ['args' => ['confident' => true, 'reply' => 'Përshëndetje! Jam Maja.', 'kind' => 'small_talk'], 'toolsUsed' => []];
            });
            $mock->shouldReceive('structured')->once()->andReturn(['small_talk' => true]);
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->runJob($thread, $message);

        $this->assertStringContainsString('Maja', $capturedSystem);
        $this->assertStringContainsString('Serioze, e shkurtër dhe pa emoji.', $capturedSystem);
    }

    /** Task #368: "koha e shkrimit" rritet me gjatësinë — kufij 2s dhe 10s. */
    public function test_human_delay_scales_with_reply_length(): void
    {
        $this->assertSame(2, GenerateAiGuestReply::humanDelaySeconds(''));
        $this->assertSame(5, GenerateAiGuestReply::humanDelaySeconds(str_repeat('a', 120)));
        $this->assertSame(10, GenerateAiGuestReply::humanDelaySeconds(str_repeat('a', 400)));
    }

    public function test_staff_reply_clears_the_ai_draft(): void
    {
        [$thread] = $this->makeThreadWithGuestMessage();
        $thread->forceFill(['ai_suggestion' => 'Draft AI', 'ai_suggested_at' => now()])->save();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = \App\Models\User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $admin->assignRole('admin');

        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->actingAs($admin)
            ->post(route('messages.reply', $thread), ['body' => 'Staff reply'])
            ->assertRedirect();

        $this->assertNull($thread->refresh()->ai_suggestion);
    }
}
