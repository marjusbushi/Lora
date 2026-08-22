<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiGuestReply;
use App\Models\Guest;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\AiConversationBooking;
use App\Services\GeminiClient;
use App\Services\PokPayments;
use App\Services\WhatsAppBridgeClient;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Hapi 3 i Lora recepsionistes (task #365): mysafiri zgjedh ofertën në bisedë →
 * mbajtje PENDING race-safe → link POK → konfirmim VETËM nga pagesa → përmbledhje
 * në thread. Gardat: çelës default OFF, kurrë në thread OTA, lirim pa pagesë.
 */
class AiConversationBookingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);

        config()->set('services.pok.merchant_id', 'M-1');
        config()->set('services.pok.key_id', 'kid');
        config()->set('services.pok.key_secret', 'ksecret');
        config()->set('services.pok.production', false);
        config()->set('services.pok.base_url', 'https://api-staging.pokpay.io');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function fakePok(float $amount = 190.0): void
    {
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok', 'expiresIn' => 3600000]], 200),
            '*/sdk-orders/*' => Http::response(['data' => ['sdkOrder' => [
                'id' => 'ord_ai_1', 'isCompleted' => true, 'isCanceled' => false,
                'isRefunded' => false, 'finalAmount' => $amount, 'currencyCode' => 'EUR',
            ]]], 200),
            // Id UNIK per porosi (si POK reale) — reservations ka UNIQUE(pok_order_id).
            '*/sdk-orders' => function () use ($amount) {
                static $i = 0;
                $i++;

                return Http::response(['data' => ['sdkOrder' => ['id' => 'ord_ai_'.$i, 'finalAmount' => $amount, 'currencyCode' => 'EUR']]], 200);
            },
        ]);
    }

    private function roomOfType(string $name = 'Dhomë Dyshe', float $base = 95, string $number = '101'): Room
    {
        $type = RoomType::query()->firstOrCreate(['name' => $name], ['base_price' => $base, 'max_occupancy' => 3, 'amenities' => []]);

        return Room::create(['room_type_id' => $type->id, 'room_number' => $number, 'floor' => 1, 'status' => 'available']);
    }

    private function whatsappThread(): MessageThread
    {
        return MessageThread::create([
            'channel' => 'whatsapp',
            'whatsapp_jid' => '355691234567@s.whatsapp.net',
            'guest_name' => 'Andi Guest',
            'status' => 'open',
        ]);
    }

    private function enableBooking(): void
    {
        // Rezervimi kërkon EDHE auto-përgjigjet WhatsApp (Codex #504 P2) —
        // pa to linku i pagesës do mbetej draft me dhomën të bllokuar.
        Setting::set('ai_mcp.whatsapp_booking_enabled', true, 'boolean');
        Setting::set('ai_mcp.whatsapp_auto_reply_enabled', true, 'boolean');
    }

    private function holdArgs(array $overrides = []): array
    {
        return array_merge([
            'check_in' => now()->addDays(7)->toDateString(),
            'check_out' => now()->addDays(9)->toDateString(),
            'adults' => 2,
            'room_type' => 'Dhomë Dyshe',
            'guest_first_name' => 'Andi',
            'guest_last_name' => 'Hoxha',
        ], $overrides);
    }

    public function test_guest_choice_creates_a_pending_hold_with_pok_link_and_links_the_thread(): void
    {
        $this->fakePok();
        $this->enableBooking();
        $this->roomOfType();
        $thread = $this->whatsappThread();

        $result = app(AiConversationBooking::class)->hold($thread, $this->holdArgs());

        $this->assertSame('hold_created', $result['status'] ?? null, json_encode($result));
        $this->assertStringContainsString('/pay/', $result['payment_link'].'/pay/');

        $reservation = Reservation::query()->sole();
        $this->assertSame('pending', $reservation->status);
        $this->assertSame('direct', $reservation->channel);
        $this->assertSame(Reservation::CREATED_VIA_AI, $reservation->created_via);
        $this->assertSame('ord_ai_1', $reservation->pok_order_id);
        $this->assertSame($reservation->id, $thread->refresh()->reservation_id);
        $this->assertSame((float) $reservation->total_amount, $result['stay_total']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message.ai_booking_hold', 'source' => 'ai']);
    }

    public function test_a_second_hold_cannot_take_the_last_room_for_the_same_dates(): void
    {
        $this->fakePok();
        $this->enableBooking();
        $this->roomOfType(); // vetëm NJË dhomë e tipologjisë
        $thread = $this->whatsappThread();

        $first = app(AiConversationBooking::class)->hold($thread, $this->holdArgs());
        $second = app(AiConversationBooking::class)->hold($this->whatsappThread(), $this->holdArgs());

        $this->assertSame('hold_created', $first['status'] ?? null);
        $this->assertArrayHasKey('error', $second);
        $this->assertSame(1, Reservation::query()->count());
    }

    public function test_an_ota_thread_is_always_refused(): void
    {
        $this->fakePok();
        $this->enableBooking();
        $this->roomOfType();
        $thread = MessageThread::create(['channel' => 'booking.com', 'channex_thread_id' => 'TH-OTA', 'guest_name' => 'Ota Guest', 'status' => 'open']);

        $booking = app(AiConversationBooking::class);

        $this->assertFalse($booking->availableFor($thread));
        $result = $booking->hold($thread, $this->holdArgs());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_switch_off_no_code_path_creates_a_reservation(): void
    {
        $this->fakePok();
        $this->roomOfType();
        $thread = $this->whatsappThread();

        $booking = app(AiConversationBooking::class);

        // Default OFF: mjeti as nuk deklarohet, dhe executor-i refuzon vetë.
        $this->assertFalse($booking->availableFor($thread));
        $result = $booking->hold($thread, $this->holdArgs());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Reservation::query()->count());
        Http::assertNothingSent();
    }

    public function test_booking_switch_alone_is_not_enough_without_whatsapp_auto_replies(): void
    {
        $this->fakePok();
        Setting::set('ai_mcp.whatsapp_booking_enabled', true, 'boolean');
        // auto-përgjigjet WhatsApp mbeten OFF — mbajtja do krijohej po linku
        // s'do t'i shkonte kurrë mysafirit (vetëm draft): refuzo që në burim.
        $this->roomOfType();
        $thread = $this->whatsappThread();

        $booking = app(AiConversationBooking::class);

        $this->assertFalse($booking->availableFor($thread));
        $result = $booking->hold($thread, $this->holdArgs());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_a_retry_with_the_same_confirmation_reuses_the_existing_hold(): void
    {
        $this->fakePok();
        $this->enableBooking();
        $this->roomOfType();
        $thread = $this->whatsappThread();

        $first = app(AiConversationBooking::class)->hold($thread, $this->holdArgs());
        // Riprova e job-it ri-ekzekuton bisedën me TË NJËJTIN konfirmim —
        // duhet të rikthehet e njëjta mbajtje, jo një dublikatë (Codex #504 P1).
        $second = app(AiConversationBooking::class)->hold($thread->fresh(), $this->holdArgs());

        $this->assertSame('hold_created', $second['status'] ?? null, json_encode($second));
        $this->assertSame($first['payment_link'], $second['payment_link']);
        $this->assertSame(1, Reservation::query()->count());
    }

    public function test_a_changed_confirmation_releases_the_old_hold_and_creates_a_new_one(): void
    {
        // Porosia e vjetër POK e PAPAGUAR — vetëm atëherë ndërrimi i mendjes
        // liron të vjetrën; e paguara refuzohet (testi më poshtë).
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok', 'expiresIn' => 3600000]], 200),
            '*/sdk-orders/*' => Http::response(['data' => ['sdkOrder' => [
                'id' => 'ord_ai_1', 'isCompleted' => false, 'isCanceled' => false,
                'isRefunded' => false, 'finalAmount' => 0, 'currencyCode' => 'EUR',
            ]]], 200),
            '*/sdk-orders' => function () {
                static $i = 0;
                $i++;

                return Http::response(['data' => ['sdkOrder' => ['id' => 'ord_ai_'.$i, 'finalAmount' => 190, 'currencyCode' => 'EUR']]], 200);
            },
        ]);
        $this->enableBooking();
        $this->roomOfType('Dhomë Dyshe', 95, '101');
        $this->roomOfType('Suitë', 150, '201');
        $thread = $this->whatsappThread();

        app(AiConversationBooking::class)->hold($thread, $this->holdArgs());
        // Mysafiri ndërroi mendje → tipologji tjetër: mbajtja e vjetër lirohet.
        $second = app(AiConversationBooking::class)->hold($thread->fresh(), $this->holdArgs(['room_type' => 'Suitë']));

        $this->assertSame('hold_created', $second['status'] ?? null, json_encode($second));
        $this->assertSame(1, Reservation::query()->where('status', 'pending')->count());
        $this->assertSame(1, Reservation::query()->where('status', 'cancelled')->count());
    }

    public function test_pok_settle_confirms_and_sends_the_summary_into_the_thread(): void
    {
        $this->fakePok();
        $this->enableBooking();
        $this->roomOfType();
        $thread = $this->whatsappThread();

        $hold = app(AiConversationBooking::class)->hold($thread, $this->holdArgs());
        $this->assertSame('hold_created', $hold['status'] ?? null);
        $reservation = Reservation::query()->sole();

        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('send')->once()
                ->withArgs(fn ($tenantId, $jid, $text) => $jid === '355691234567@s.whatsapp.net'
                    && str_contains($text, 'Pagesa u konfirmua')
                    && str_contains($text, 'Payment confirmed'))
                ->andReturn(['id' => 'wa-conf-1']);
        });

        $this->assertTrue(app(PokPayments::class)->settle($reservation));

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertDatabaseHas('messages', [
            'message_thread_id' => $thread->id,
            'sender' => Message::SENDER_HOST,
            'sent_by_ai' => true,
            'whatsapp_message_id' => 'wa-conf-1',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message.ai_booking_confirmed', 'source' => 'ai']);

        // Idempotencë: një settle i dytë s'dërgon përmbledhje të dytë (mock once).
        app(PokPayments::class)->settle($reservation->fresh());
    }

    public function test_release_unpaid_frees_the_ai_hold_after_the_window(): void
    {
        // Porosia POK mbetet E PAPAGUAR që nga fillimi — komanda e lirimit e
        // ri-verifikon me POK para se të lirojë (një mbajtje e paguar
        // konfirmohet, s'lirohet). Stub-i i PARË i Http::fake fiton, ndaj
        // fake-u i pagesës ndërtohet i saktë këtu, jo me fakePok().
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok', 'expiresIn' => 3600000]], 200),
            '*/sdk-orders/*' => Http::response(['data' => ['sdkOrder' => [
                'id' => 'ord_ai_1', 'isCompleted' => false, 'isCanceled' => false,
                'isRefunded' => false, 'finalAmount' => 0, 'currencyCode' => 'EUR',
            ]]], 200),
            '*/sdk-orders' => Http::response(['data' => ['sdkOrder' => ['id' => 'ord_ai_1', 'finalAmount' => 190, 'currencyCode' => 'EUR']]], 200),
        ]);
        $this->enableBooking();
        $room = $this->roomOfType();
        $thread = $this->whatsappThread();

        app(AiConversationBooking::class)->hold($thread, $this->holdArgs());
        $reservation = Reservation::query()->sole();
        Reservation::whereKey($reservation->id)->update(['created_at' => now()->subMinutes(40)]);

        $this->artisan('pok:release-unpaid', ['--tenant' => $this->tenant->id])->assertSuccessful();

        $this->assertNotSame('pending', $reservation->fresh()->status);
        $this->assertTrue(Reservation::isRoomAvailable($room->id, $this->holdArgs()['check_in'], $this->holdArgs()['check_out']));
    }

    public function test_existing_guest_is_reused_by_phone_without_overwriting(): void
    {
        $this->fakePok();
        $this->enableBooking();
        $this->roomOfType();
        $existing = Guest::create(['first_name' => 'Origjinal', 'last_name' => 'Emri', 'phone' => '+355 69 123 4567']);
        $thread = $this->whatsappThread();

        app(AiConversationBooking::class)->hold($thread, $this->holdArgs(['guest_first_name' => 'Tjetër', 'guest_last_name' => 'Emër']));

        $reservation = Reservation::query()->sole();
        $this->assertSame($existing->id, $reservation->guest_id);
        $this->assertSame('Origjinal', $existing->fresh()->first_name);
        $this->assertSame('Emri', $existing->fresh()->last_name);
    }

    public function test_full_job_flow_sends_the_payment_link_and_passes_the_number_gate(): void
    {
        $this->fakePok();
        $this->enableBooking();
        Setting::set('ai_mcp.whatsapp_auto_reply_enabled', true, 'boolean');
        $this->roomOfType();
        $thread = $this->whatsappThread();
        $message = $thread->messages()->create([
            'sender' => Message::SENDER_GUEST,
            'body' => 'Po, e konfirmoj Dhomë Dyshe — Andi Hoxha.',
            'sent_at' => now(),
        ]);

        $checkIn = now()->addDays(7)->toDateString();
        $checkOut = now()->addDays(9)->toDateString();

        // Mock-u luan Gemini-n: thërret executor-in REAL create_booking_hold
        // dhe ndërton përgjigjen me linkun + numrat që ktheu mjeti.
        $this->mock(GeminiClient::class, function ($mock) use ($checkIn, $checkOut) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) use ($checkIn, $checkOut) {
                $names = array_column($tools, 'name');
                if (! in_array('create_booking_hold', $names, true)) {
                    throw new \RuntimeException('Mjeti create_booking_hold duhej deklaruar për këtë bisedë.');
                }

                $hold = $executors['create_booking_hold']([
                    'check_in' => $checkIn, 'check_out' => $checkOut, 'adults' => 2,
                    'room_type' => 'Dhomë Dyshe', 'guest_first_name' => 'Andi', 'guest_last_name' => 'Hoxha',
                ]);

                return [
                    'args' => [
                        'confident' => true,
                        'reply' => "Dhoma u mbajt! {$hold['room_type']}, {$hold['nights']} net, totali {$hold['stay_total']} {$hold['currency']}. Paguani këtu brenda {$hold['payment_deadline_minutes']} minutash: {$hold['payment_link']}",
                    ],
                    'toolsUsed' => ['create_booking_hold'],
                ];
            });
        });

        $sentBody = null;
        $this->mock(WhatsAppBridgeClient::class, function ($mock) use (&$sentBody) {
            $mock->shouldReceive('typing')->andReturn([]);
            $mock->shouldReceive('send')->once()
                ->withArgs(function ($tenantId, $jid, $text) use (&$sentBody) {
                    $sentBody = $text;

                    return true;
                })
                ->andReturn(['id' => 'wa-book-1']);
        });

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        // Linku i vërtetë i pagesës doli te mysafiri — porta e shifrave e la
        // të kalojë sepse përputhet SAKTËSISHT me atë që ktheu mjeti.
        $reservation = Reservation::query()->sole();
        $this->assertNotNull($sentBody);
        $this->assertStringContainsString(route('website.pay.show', $reservation->confirmation_token), $sentBody);
        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    public function test_a_tampered_payment_link_falls_to_the_number_gate(): void
    {
        $this->fakePok();
        $this->enableBooking();
        Setting::set('ai_mcp.whatsapp_auto_reply_enabled', true, 'boolean');
        $this->roomOfType();
        $thread = $this->whatsappThread();
        $message = $thread->messages()->create([
            'sender' => Message::SENDER_GUEST,
            'body' => 'Po, e konfirmoj.',
            'sent_at' => now(),
        ]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $hold = $executors['create_booking_hold']([
                    'check_in' => now()->addDays(7)->toDateString(),
                    'check_out' => now()->addDays(9)->toDateString(),
                    'adults' => 2, 'room_type' => 'Dhomë Dyshe', 'guest_first_name' => 'Andi',
                ]);
                if (isset($hold['error'])) {
                    throw new \RuntimeException('hold error: '.$hold['error']);
                }

                // Modeli "shpik" një link tjetër me shifra — s'përputhet me
                // mjetin → duhet të bjerë në draft, kurrë te mysafiri.
                return [
                    'args' => ['confident' => true, 'reply' => "Paguani te https://evil.example/pay/99887766 totalin {$hold['stay_total']} {$hold['currency']}."],
                    'toolsUsed' => ['create_booking_hold'],
                ];
            });
        });
        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('typing')->andReturn([]);
            $mock->shouldReceive('send')->never();
        });

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    public function test_a_digit_free_tampered_link_still_falls_to_draft(): void
    {
        $this->fakePok();
        $this->enableBooking();
        $this->roomOfType();
        $thread = $this->whatsappThread();
        $message = $thread->messages()->create(['sender' => Message::SENDER_GUEST, 'body' => 'Po, e konfirmoj.', 'sent_at' => now()]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $hold = $executors['create_booking_hold']([
                    'check_in' => now()->addDays(7)->toDateString(),
                    'check_out' => now()->addDays(9)->toDateString(),
                    'adults' => 2, 'room_type' => 'Dhomë Dyshe', 'guest_first_name' => 'Andi',
                ]);

                // Link i falsifikuar PA ASNJË shifër — portës së numrave i
                // shpëton; porta e LINQEVE duhet ta kapë (Codex #505 P1).
                return [
                    'args' => ['confident' => true, 'reply' => "Paguani te https://evil.example/pay/confirm totalin {$hold['stay_total']} {$hold['currency']} brenda {$hold['payment_deadline_minutes']} minutash."],
                    'toolsUsed' => ['create_booking_hold'],
                ];
            });
        });
        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('typing')->andReturn([]);
            $mock->shouldReceive('send')->never();
        });

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    public function test_a_changed_confirmation_is_refused_when_the_old_hold_was_already_paid(): void
    {
        $this->fakePok(); // GET e porosisë kthen TË PAGUAR (isCompleted=true, 190 EUR)
        $this->enableBooking();
        $this->roomOfType('Dhomë Dyshe', 95, '101');
        $this->roomOfType('Suitë', 150, '201');
        $thread = $this->whatsappThread();

        // Përmbledhja e konfirmimit niset kur settle e gjen të paguar — mock-o urën.
        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('send')->andReturn(['id' => 'wa-x']);
        });

        app(AiConversationBooking::class)->hold($thread, $this->holdArgs());
        $old = Reservation::query()->sole();

        // Mysafiri "ndërron mendje" — po forma e vjetër POK u pagua ndërkohë:
        // pajtimi e konfirmon të vjetrën dhe ndryshimi refuzohet (Codex #505 P1).
        $second = app(AiConversationBooking::class)->hold($thread->fresh(), $this->holdArgs(['room_type' => 'Suitë']));

        $this->assertArrayHasKey('error', $second);
        $this->assertStringContainsString('PAGUAR', $second['error']);
        $this->assertSame('confirmed', $old->fresh()->status);
        $this->assertSame(1, Reservation::query()->count());
    }

    public function test_terminal_job_failure_releases_the_undelivered_unpaid_hold(): void
    {
        // Porosia POK e PAPAGUAR — dështimi terminal duhet ta lirojë dhomën.
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok', 'expiresIn' => 3600000]], 200),
            '*/sdk-orders/*' => Http::response(['data' => ['sdkOrder' => [
                'id' => 'ord_ai_1', 'isCompleted' => false, 'isCanceled' => false,
                'isRefunded' => false, 'finalAmount' => 0, 'currencyCode' => 'EUR',
            ]]], 200),
            '*/sdk-orders' => Http::response(['data' => ['sdkOrder' => ['id' => 'ord_ai_1', 'finalAmount' => 190, 'currencyCode' => 'EUR']]], 200),
        ]);
        $this->enableBooking();
        $this->roomOfType();
        $thread = $this->whatsappThread();
        $message = $thread->messages()->create(['sender' => Message::SENDER_GUEST, 'body' => 'Po, e konfirmoj.', 'sent_at' => now()]);

        app(AiConversationBooking::class)->hold($thread, $this->holdArgs());
        $hold = Reservation::query()->sole();

        $job = new GenerateAiGuestReply($thread->id, $message->id);
        app(TenantContext::class)->clear();
        $job->failed(new \RuntimeException('raundi final dështoi pas mbajtjes'));
        app(TenantContext::class)->set($this->tenant);

        $this->assertSame('cancelled', $hold->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message.ai_booking_hold_released', 'source' => 'ai']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message.ai_reply_failed', 'source' => 'ai']);
    }

    /** Task #403: dështim KALIMTAR (5xx) → riprova e ftohtë u planifikua — mbajtja NUK lirohet, riprova e ripërdor. */
    public function test_transient_failure_keeps_the_undelivered_hold_for_the_cool_retry(): void
    {
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok', 'expiresIn' => 3600000]], 200),
            '*/sdk-orders/*' => Http::response(['data' => ['sdkOrder' => [
                'id' => 'ord_ai_1', 'isCompleted' => false, 'isCanceled' => false,
                'isRefunded' => false, 'finalAmount' => 0, 'currencyCode' => 'EUR',
            ]]], 200),
            '*/sdk-orders' => Http::response(['data' => ['sdkOrder' => ['id' => 'ord_ai_1', 'finalAmount' => 190, 'currencyCode' => 'EUR']]], 200),
        ]);
        $this->enableBooking();
        $this->roomOfType();
        $thread = $this->whatsappThread();
        $message = $thread->messages()->create(['sender' => Message::SENDER_GUEST, 'body' => 'Po, e konfirmoj.', 'sent_at' => now()]);

        app(AiConversationBooking::class)->hold($thread, $this->holdArgs());
        $hold = Reservation::query()->sole();

        \Illuminate\Support\Facades\Queue::fake();

        $job = new GenerateAiGuestReply($thread->id, $message->id);
        app(TenantContext::class)->clear();
        $job->failed(new \RuntimeException('Google ktheu një gabim (503). Provo sërish.'));
        app(TenantContext::class)->set($this->tenant);

        // Mbajtja mbetet — riprova 5-min e ripërdor me idempotencë (i njëjti link).
        $this->assertSame('pending', $hold->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'message.ai_booking_hold_released']);
        \Illuminate\Support\Facades\Queue::assertPushed(GenerateAiGuestReply::class, 1);
    }

    public function test_a_bare_tampered_link_without_scheme_still_falls_to_draft(): void
    {
        $this->fakePok();
        $this->enableBooking();
        $this->roomOfType();
        $thread = $this->whatsappThread();
        $message = $thread->messages()->create(['sender' => Message::SENDER_GUEST, 'body' => 'Po, e konfirmoj.', 'sent_at' => now()]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $hold = $executors['create_booking_hold']([
                    'check_in' => now()->addDays(7)->toDateString(),
                    'check_out' => now()->addDays(9)->toDateString(),
                    'adults' => 2, 'room_type' => 'Dhomë Dyshe', 'guest_first_name' => 'Andi',
                ]);

                // Link LAKURIQ pa https:// — WhatsApp e bën klikueshëm njësoj
                // (Codex #506 P1): duhet të bjerë në draft.
                return [
                    'args' => ['confident' => true, 'reply' => "Paguani te evil.example/pay/confirm totalin {$hold['stay_total']} {$hold['currency']}."],
                    'toolsUsed' => ['create_booking_hold'],
                ];
            });
        });
        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('typing')->andReturn([]);
            $mock->shouldReceive('send')->never();
        });

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Codex #566 P1: kuota e provës së BRAKTISUR nuk "ankoron" dot përgjigjen e rezervës ndër-provider. */
    public function test_cross_fallback_never_reuses_stale_quotes_from_the_abandoned_attempt(): void
    {
        $this->roomOfType(); // 95/natë → 190 për 2 net
        $thread = $this->whatsappThread();
        $message = $thread->messages()->create(['sender' => Message::SENDER_GUEST, 'body' => 'Sa kushton 28-30 gusht?', 'sent_at' => now()]);
        \App\Models\Setting::set('ai_mcp.whatsapp_auto_reply_enabled', true, 'boolean');
        \App\Models\PlatformSetting::set('ai.cross_provider_fallback', '1', 'boolean');

        // Prova 1 (gemini): thërret mjetin REAL — kuota 190 mblidhet — pastaj 503.
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->once()->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $executors['check_availability']([
                    'check_in' => now()->addDays(7)->toDateString(),
                    'check_out' => now()->addDays(9)->toDateString(),
                    'adults' => 2,
                ]);

                throw new \RuntimeException('Google ktheu një gabim (503). Provo sërish.');
            });
        });
        // Prova 2 (openai): PRETENDON se thirri mjete, por s'i thirri — përgjigja
        // "190 EUR" do të ankorohej VETËM nga kuota e vjetruar e provës 1.
        $this->mock(\App\Services\OpenAiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->once()->andReturn([
                'args' => ['confident' => true, 'reply' => 'Totali 190 EUR për 2 net.', 'kind' => 'informative'],
                'toolsUsed' => ['check_availability'],
                'usage' => ['input' => 10, 'output' => 5, 'thinking' => 0, 'provider' => 'openai', 'model' => 'gpt-test-luna'],
            ]);
        });
        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('typing')->andReturn([]);
            $mock->shouldReceive('send')->never();
        });

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        // Mbledhësit u zeruan për provën e re → toolsUsed pa kuota reale = DRAFT.
        // (Pa zerimin, kuota 190 e provës së braktisur do ta DËRGONTE.)
        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Task #408 (kriteri 2): portat e sigurisë veprojnë NJËSOJ mbi shoferin OpenAI — link i huaj → draft. */
    public function test_openai_provider_flows_through_the_same_safety_gates(): void
    {
        $this->fakePok();
        $this->enableBooking();
        $this->roomOfType();
        $thread = $this->whatsappThread();
        $message = $thread->messages()->create(['sender' => Message::SENDER_GUEST, 'body' => 'Po, e konfirmoj.', 'sent_at' => now()]);

        // Tenanti kalon te openai nga PLATFORMA (super-admin) — jo nga hoteli.
        \App\Models\PlatformSetting::set('ai.provider_overrides', [(string) $this->tenant->id => 'openai'], 'json');

        $this->mock(\App\Services\OpenAiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->once()->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $hold = $executors['create_booking_hold']([
                    'check_in' => now()->addDays(7)->toDateString(),
                    'check_out' => now()->addDays(9)->toDateString(),
                    'adults' => 2, 'room_type' => 'Dhomë Dyshe', 'guest_first_name' => 'Andi',
                ]);

                // I njëjti sulm si testi i Gemini-t: link lakuriq i huaj në
                // vend të payment_link — porta duhet ta rrëzojë NJËSOJ.
                return [
                    'args' => ['confident' => true, 'reply' => "Paguani te evil.example/pay/confirm totalin {$hold['stay_total']} {$hold['currency']}."],
                    'toolsUsed' => ['create_booking_hold'],
                    'usage' => ['input' => 10, 'output' => 5, 'thinking' => 0, 'provider' => 'openai', 'model' => 'gpt-test-luna'],
                ];
            });
        });
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('converse')->never());
        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('typing')->andReturn([]);
            $mock->shouldReceive('send')->never();
        });

        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    public function test_terminal_failure_of_a_later_job_never_cancels_a_delivered_hold(): void
    {
        // Porosia POK e PAPAGUAR — po mbajtja i përket një mesazhi TË MËPARSHËM
        // linku i së cilës u DORËZUA: mysafiri mund të paguajë me të.
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok', 'expiresIn' => 3600000]], 200),
            '*/sdk-orders/*' => Http::response(['data' => ['sdkOrder' => [
                'id' => 'ord_ai_1', 'isCompleted' => false, 'isCanceled' => false,
                'isRefunded' => false, 'finalAmount' => 0, 'currencyCode' => 'EUR',
            ]]], 200),
            '*/sdk-orders' => Http::response(['data' => ['sdkOrder' => ['id' => 'ord_ai_1', 'finalAmount' => 190, 'currencyCode' => 'EUR']]], 200),
        ]);
        $this->enableBooking();
        $this->roomOfType();
        $thread = $this->whatsappThread();
        $thread->messages()->create(['sender' => Message::SENDER_GUEST, 'body' => 'Po, e konfirmoj.', 'sent_at' => now()->subMinutes(10)]);

        app(AiConversationBooking::class)->hold($thread, $this->holdArgs());
        $hold = Reservation::query()->sole();

        // Linku u DORËZUA (mesazh AI pas krijimit të mbajtjes)…
        $thread->messages()->create(['sender' => Message::SENDER_HOST, 'sent_by_ai' => true, 'body' => 'Linku i pagesës: ...', 'sent_at' => now()->addSecond()]);
        // …dhe një mesazh i RI mysafiri sjell një job të ri që dështon terminal.
        $later = $thread->messages()->create(['sender' => Message::SENDER_GUEST, 'body' => 'Faleminderit!', 'sent_at' => now()->addSeconds(2)]);

        $job = new GenerateAiGuestReply($thread->id, $later->id);
        app(TenantContext::class)->clear();
        $job->failed(new \RuntimeException('gemini down'));
        app(TenantContext::class)->set($this->tenant);

        // Mbajtja e dorëzuar mbetet e paprekur — kurrë anulim i një linku live.
        $this->assertSame('pending', $hold->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'message.ai_booking_hold_released']);
    }

    public function test_terminal_job_failure_confirms_instead_of_releasing_when_the_hold_was_paid(): void
    {
        $this->fakePok(); // GET: E PAGUAR
        $this->enableBooking();
        $this->roomOfType();
        $thread = $this->whatsappThread();
        $message = $thread->messages()->create(['sender' => Message::SENDER_GUEST, 'body' => 'Po, e konfirmoj.', 'sent_at' => now()]);

        $this->mock(WhatsAppBridgeClient::class, function ($mock) {
            $mock->shouldReceive('send')->andReturn(['id' => 'wa-x']);
        });

        app(AiConversationBooking::class)->hold($thread, $this->holdArgs());
        $hold = Reservation::query()->sole();

        $job = new GenerateAiGuestReply($thread->id, $message->id);
        app(TenantContext::class)->clear();
        $job->failed(new \RuntimeException('raundi final dështoi pas mbajtjes'));
        app(TenantContext::class)->set($this->tenant);

        // Pajtimi me POK fiton: e paguara KONFIRMOHET, kurrë s'lirohet.
        $this->assertSame('confirmed', $hold->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'message.ai_booking_hold_released']);
    }

    /**
     * Kundër API-së së VËRTETË të Gemini-t (mësimi i #379 — mock-u s'e provon
     * dot sjelljen e modelit): kur mysafiri KONFIRMON ofertën me të dhëna të
     * plota, modeli real duhet ta thërrasë create_booking_hold me skemën tonë
     * dhe të kthejë përgjigje me linkun e mjetit. Vetëm me GEMINI_REAL_API=1.
     */
    #[\PHPUnit\Framework\Attributes\Group('real-api')]
    public function test_real_api_model_calls_the_booking_tool_when_the_guest_confirms(): void
    {
        $key = (string) env('GEMINI_API_KEY', env('GOOGLE_API_KEY'));
        if ($key === '' || ! env('GEMINI_REAL_API')) {
            $this->markTestSkipped('Kërkon GEMINI_API_KEY dhe GEMINI_REAL_API=1 (thirrje e paguar kundër API-së reale).');
        }

        Http::allowStrayRequests();
        config()->set('services.gemini.key', $key);
        config()->set('services.gemini.model', 'gemini-3.7-flash');
        config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');

        $tools = [
            [
                'name' => 'create_booking_hold',
                'description' => 'Krijon rezervimin PENDING me dhomë të mbajtur dhe kthen linkun e pagesës. Thirre VETËM pasi mysafiri zgjodhi ofertën dhe konfirmoi datat, personat, tipologjinë dhe emrin e plotë.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'check_in' => ['type' => 'string'], 'check_out' => ['type' => 'string'],
                        'adults' => ['type' => 'integer'], 'room_type' => ['type' => 'string'],
                        'guest_first_name' => ['type' => 'string'], 'guest_last_name' => ['type' => 'string'],
                    ],
                    'required' => ['check_in', 'check_out', 'adults', 'room_type', 'guest_first_name'],
                ],
            ],
            [
                'name' => 'guest_reply',
                'description' => 'Përgjigja e strukturuar për mysafirin.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'confident' => ['type' => 'boolean'],
                        'reply' => ['type' => 'string'],
                        'kind' => ['type' => 'string', 'enum' => ['small_talk', 'informative', 'clarifying']],
                    ],
                    'required' => ['confident', 'reply', 'kind'],
                ],
            ],
        ];

        $held = null;
        $result = app(GeminiClient::class)->converse(
            "Je Lora, recepsionistja e hotelit. Mysafiri zgjodhi një ofertë dhe konfirmoi të dhënat — thirr create_booking_hold dhe pastaj dërgoja linkun e pagesës saktësisht siç e kthen mjeti, me totalin. Mbylle me guest_reply.\nRREZERVIMI: pas konfirmimit të mysafirit thirr mjetin; shifrat vetëm nga mjeti.",
            "BISEDA:\nHOTELI: Dhomë Dyshe Standard: 95 EUR/nata, totali 190 EUR për 28-30 gusht 2026. E rezervojmë?\nMYSAFIRI: Po, e konfirmoj: Dhomë Dyshe Standard, 28-30 gusht 2026, 2 persona, Andi Hoxha.",
            $tools,
            [
                'create_booking_hold' => function (array $args) use (&$held): array {
                    $held = $args;

                    return [
                        'status' => 'hold_created',
                        'room_type' => 'Dhomë Dyshe Standard',
                        'check_in' => '2026-08-28', 'check_out' => '2026-08-30',
                        'nights' => 2, 'adults' => 2,
                        'stay_total' => 190.0, 'currency' => 'EUR',
                        'payment_link' => 'https://villamucho.com/pay/abc123token',
                        'payment_deadline_minutes' => 30,
                    ];
                },
            ],
            'guest_reply',
            1024,
            75,
        );

        $this->assertContains('create_booking_hold', $result['toolsUsed']);
        $this->assertSame('2026-08-28', $held['check_in'] ?? null);
        $this->assertSame('Dhomë Dyshe Standard', $held['room_type'] ?? null);
        $this->assertStringContainsString('https://villamucho.com/pay/abc123token', $result['args']['reply']);
        $this->assertStringContainsString('190', $result['args']['reply']);
    }
}
