<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiGuestReply;
use App\Models\Guest;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChannexClient;
use App\Services\GeminiClient;
use App\Services\ThreadReservationContext;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lora recepsioniste · Hapi 2 (task #364): mjeti get_thread_reservation —
 * rezervimi i LIDHUR me bisedën, identiteti VETËM nga thread-i (anti-rrjedhje).
 */
class AiGuestReservationContextTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($this->tenant);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function makeReservation(array $guestAttrs = [], array $attrs = []): Reservation
    {
        $type = RoomType::create(['name' => 'Dhomë Deluxe', 'base_price' => 100, 'max_occupancy' => 2]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(array_merge([
            'first_name' => 'Ardit', 'last_name' => 'Hoxha', 'email' => 'ardit@example.com', 'phone' => '+355 69 123 4567',
        ], $guestAttrs));
        $creator = User::systemForCurrentTenant();

        return Reservation::create(array_merge([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(5)->toDateString(),
            'check_out_date' => now()->addDays(8)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 300,
            'adults' => 2,
            'children' => 0,
            'channel' => 'direct',
            'created_by' => $creator->id,
        ], $attrs));
    }

    private function makeThread(array $attrs = []): array
    {
        $thread = MessageThread::create(array_merge([
            'channex_thread_id' => 'thr-'.uniqid(),
            'channel' => 'booking.com',
            'guest_name' => 'Guest Test',
            'status' => 'open',
        ], $attrs));
        $message = $thread->messages()->create([
            'sender' => Message::SENDER_GUEST,
            'body' => 'Sa kam për të paguar?',
            'sent_at' => now(),
        ]);

        return [$thread, $message];
    }

    private function runJob(MessageThread $thread, Message $message): void
    {
        app()->call([new GenerateAiGuestReply($thread->id, $message->id), 'handle']);
    }

    /** Kriteri #1: thread me reservation_id → Lora dërgon datat/bilancin REAL (auto edhe me 0 FAQ — grounded). */
    public function test_linked_thread_sends_real_balance(): void
    {
        $reservation = $this->makeReservation();
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 100, 'method' => 'cash']);
        [$thread, $message] = $this->makeThread(['reservation_id' => $reservation->id]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $context = $executors['get_thread_reservation']([]);
                \PHPUnit\Framework\Assert::assertSame(200.0, $context['balance']);

                return [
                    'args' => ['confident' => true, 'reply' => "Bilanci juaj: {$context['balance']} {$context['currency']} (paguar {$context['paid']} nga {$context['total']})."],
                    'toolsUsed' => ['get_thread_reservation'],
                ];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')
            ->once()->withArgs(fn ($threadId, $body) => str_contains($body, '200')));

        $this->runJob($thread, $message);

        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Kriteri #2 (roja anti-rrjedhje): thread pa lidhje → error pa ASNJË të dhënë personale; përgjigja mbetet draft. */
    public function test_unlinked_thread_refuses_and_leaks_nothing(): void
    {
        $this->makeReservation(); // ekziston një rezervim në sistem — po JO i kësaj bisede
        [$thread, $message] = $this->makeThread();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $context = $executors['get_thread_reservation']([]);

                \PHPUnit\Framework\Assert::assertArrayHasKey('error', $context);
                \PHPUnit\Framework\Assert::assertCount(1, $context, 'Errori duhet të jetë fusha e VETME — asnjë e dhënë personale bashkë me të.');

                return ['args' => ['confident' => false, 'reply' => 'Recepsioni do t\'ju përgjigjet shumë shpejt.'], 'toolsUsed' => ['get_thread_reservation']];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
    }

    /** Kriteri #3 (strukturor): argumentet e AI-së INJOROHEN — edhe në kërkoftë rezervimin e tjetërkujt, merr të thread-it. */
    public function test_injected_args_cannot_reach_another_reservation(): void
    {
        $mine = $this->makeReservation();
        $other = Reservation::create([
            'room_id' => $mine->room_id,
            'guest_id' => Guest::create(['first_name' => 'Tjetër', 'last_name' => 'Person', 'email' => 'tjeter@example.com'])->id,
            'check_in_date' => now()->addDays(20)->toDateString(),
            'check_out_date' => now()->addDays(22)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 999,
            'adults' => 1,
            'channel' => 'direct',
            'created_by' => User::systemForCurrentTenant()->id,
        ]);
        [$thread, $message] = $this->makeThread(['reservation_id' => $mine->id]);

        $this->mock(GeminiClient::class, function ($mock) use ($other, $mine) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) use ($other, $mine) {
                // "Injektim": AI-ja (e mashtruar nga mysafiri) kërkon rezervimin e tjetrit.
                $context = $executors['get_thread_reservation'](['reservation_id' => $other->id, 'guest_name' => 'Tjetër Person']);

                \PHPUnit\Framework\Assert::assertSame((float) $mine->total_amount, $context['total']);
                \PHPUnit\Framework\Assert::assertSame('Ardit', $context['guest_first_name']);

                return ['args' => ['confident' => true, 'reply' => "Totali juaj: {$context['total']} EUR."], 'toolsUsed' => ['get_thread_reservation']];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->runJob($thread, $message);

        $this->assertDatabaseMissing('messages', [
            'message_thread_id' => $thread->id,
            'body' => "Totali juaj: {$other->total_amount} EUR.",
        ]);
    }

    /** WhatsApp: JID-i përputhet me telefonin e mysafirit → gjendet rezervimi i ardhshëm i TIJ. */
    public function test_whatsapp_jid_resolves_reservation_by_phone(): void
    {
        $reservation = $this->makeReservation(['phone' => '+355 69 123 4567']);
        $thread = MessageThread::create([
            'whatsapp_jid' => '355691234567@s.whatsapp.net',
            'channel' => 'whatsapp',
            'guest_name' => '355691234567',
            'status' => 'open',
        ]);

        $context = app(ThreadReservationContext::class)->forThread($thread);

        $this->assertSame('Ardit', $context['guest_first_name']);
        $this->assertSame($reservation->check_in_date->format('Y-m-d'), $context['check_in']);
        $this->assertSame(300.0, $context['total']);
    }

    /** WhatsApp: numër i panjohur → refuzim i pastër, zero të dhëna. */
    public function test_whatsapp_unknown_number_gets_error(): void
    {
        $this->makeReservation(['phone' => '+355 69 123 4567']);
        $thread = MessageThread::create([
            'whatsapp_jid' => '491701112233@s.whatsapp.net',
            'channel' => 'whatsapp',
            'guest_name' => '491701112233',
            'status' => 'open',
        ]);

        $context = app(ThreadReservationContext::class)->forThread($thread);

        $this->assertArrayHasKey('error', $context);
        $this->assertCount(1, $context);
    }

    /** Pagesat e VOIDUARA s'numërohen në bilanc. */
    public function test_voided_payments_do_not_count(): void
    {
        $reservation = $this->makeReservation();
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 100, 'method' => 'cash']);
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 150, 'method' => 'cash', 'is_voided' => true]);
        [$thread] = $this->makeThread(['reservation_id' => $reservation->id]);

        $context = app(ThreadReservationContext::class)->forThread($thread);

        $this->assertSame(100.0, $context['paid']);
        $this->assertSame(200.0, $context['balance']);
    }

    /** Gjetja Codex #2 (PR #465): bilanci është ai KANONIK i folio-s — minibari hyn, si te ekrani i stafit. */
    public function test_balance_includes_folio_charges(): void
    {
        $reservation = $this->makeReservation();
        \App\Models\FolioItem::create([
            'reservation_id' => $reservation->id, 'type' => 'minibar', 'description' => 'Minibar',
            'amount' => 50, 'charge_date' => now()->toDateString(),
        ]);
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 100, 'method' => 'cash']);
        [$thread] = $this->makeThread(['reservation_id' => $reservation->id]);

        $context = app(ThreadReservationContext::class)->forThread($thread);

        $this->assertSame(350.0, $context['total']);
        $this->assertSame(250.0, $context['balance']);
    }

    /** Gjetja Codex #4 (PR #465): monedha e NGRIRË e rezervimit, jo ajo aktuale e hotelit. */
    public function test_reservation_snapshot_currency_is_reported(): void
    {
        $reservation = $this->makeReservation();
        $reservation->forceFill(['currency' => 'USD', 'exchange_rate' => 1.1])->save();
        [$thread] = $this->makeThread(['reservation_id' => $reservation->id]);

        $context = app(ThreadReservationContext::class)->forThread($thread);

        $this->assertSame('USD', $context['currency']);
    }

    /** Gjetja Codex #1 (PR #465): i njëjti numër te DY persona të ndryshëm → paqartësi → refuzim (fail-closed). */
    public function test_shared_phone_between_two_people_is_ambiguous_and_refused(): void
    {
        $this->makeReservation(['phone' => '+355 69 123 4567']);
        Guest::create(['first_name' => 'Person', 'last_name' => 'Tjetër', 'email' => 'p2@example.com', 'phone' => '069 123 4567']);
        $thread = MessageThread::create([
            'whatsapp_jid' => '355691234567@s.whatsapp.net',
            'channel' => 'whatsapp',
            'guest_name' => '355691234567',
            'status' => 'open',
        ]);

        $context = app(ThreadReservationContext::class)->forThread($thread);

        $this->assertArrayHasKey('error', $context);
    }

    /** Profilet dublikate të të NJËJTIT person (emër i njëjtë) s'janë paqartësi — zgjidhet normalisht. */
    public function test_duplicate_profiles_of_same_person_still_resolve(): void
    {
        $this->makeReservation(['phone' => '+355 69 123 4567']);
        Guest::create(['first_name' => 'Ardit', 'last_name' => 'Hoxha', 'email' => 'ardit2@example.com', 'phone' => '069 123 4567']);
        $thread = MessageThread::create([
            'whatsapp_jid' => '355691234567@s.whatsapp.net',
            'channel' => 'whatsapp',
            'guest_name' => '355691234567',
            'status' => 'open',
        ]);

        $context = app(ThreadReservationContext::class)->forThread($thread);

        $this->assertSame('Ardit', $context['guest_first_name']);
    }

    /** Gjetja Codex #3 (PR #465): bilanc i vogël 20 → AI thotë 10 → JO grounded → draft (s'ka më përjashtim ≤31). */
    public function test_hallucinated_small_balance_stays_draft(): void
    {
        $reservation = $this->makeReservation();
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 280, 'method' => 'cash']);
        [$thread, $message] = $this->makeThread(['reservation_id' => $reservation->id]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $context = $executors['get_thread_reservation']([]);
                \PHPUnit\Framework\Assert::assertSame(20.0, $context['balance']);

                // AI "korrigjon" bilancin e vogël — numri 10 s'ekziston në motor.
                return ['args' => ['confident' => true, 'reply' => 'Ju kanë mbetur vetëm 10 EUR për të paguar.'], 'toolsUsed' => ['get_thread_reservation']];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->never());

        $this->runJob($thread, $message);

        $this->assertNotNull($thread->refresh()->ai_suggestion);
        $this->assertDatabaseMissing('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }

    /** Proza me data mbetet e ankoruar: ditët/muajt e datave të mjetit lejohen si shifra në tekst. */
    public function test_prose_dates_from_tool_results_stay_grounded(): void
    {
        $reservation = $this->makeReservation([], [
            'check_in_date' => '2027-08-20', 'check_out_date' => '2027-08-23',
        ]);
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 100, 'method' => 'cash']);
        [$thread, $message] = $this->makeThread(['reservation_id' => $reservation->id]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('converse')->andReturnUsing(function ($system, $conversation, $tools, $executors) {
                $executors['get_thread_reservation']([]);

                return ['args' => ['confident' => true, 'reply' => 'Qëndrimi juaj: nga 20 deri më 23 gusht, 3 net. Bilanci: 200 EUR.'], 'toolsUsed' => ['get_thread_reservation']];
            });
        });
        $this->mock(ChannexClient::class, fn ($mock) => $mock->shouldReceive('sendThreadMessage')->once());

        $this->runJob($thread, $message);

        $this->assertDatabaseHas('messages', ['message_thread_id' => $thread->id, 'sent_by_ai' => true]);
    }
}
