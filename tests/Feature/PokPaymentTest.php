<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PokPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function configurePok(): void
    {
        config()->set('services.pok.merchant_id', 'M-1');
        config()->set('services.pok.key_id', 'kid');
        config()->set('services.pok.key_secret', 'ksecret');
        config()->set('services.pok.production', false);
        config()->set('services.pok.base_url', 'https://api-staging.pokpay.io');
    }

    /** Fake the 3 POK calls: login, create (POST .../sdk-orders), retrieve (GET .../sdk-orders/{id}). */
    private function fakePok(array $orderStatus): void
    {
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok', 'expiresIn' => 3600000]], 200),
            '*/sdk-orders/*' => Http::response(['data' => ['sdkOrder' => $orderStatus]], 200),
            '*/sdk-orders' => Http::response(['data' => ['sdkOrder' => ['id' => 'ord_1', 'finalAmount' => 150, 'currencyCode' => 'EUR']]], 200),
        ]);
    }

    private function room(float $base = 150): Room
    {
        $type = RoomType::create(['name' => 'Deluxe', 'base_price' => $base, 'max_occupancy' => 3]);

        return Room::create(['room_number' => '101', 'room_type_id' => $type->id, 'floor' => 1, 'status' => 'available']);
    }

    private function pendingReservation(Room $room, string $orderId = 'ord_1', float $total = 150): Reservation
    {
        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => Guest::create(['first_name' => 'G', 'last_name' => 'X', 'email' => 'g@x.al'])->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(4)->toDateString(),
            'status' => 'pending',
            'total_amount' => $total,
            'adults' => 1,
            'channel' => 'direct',
            'pok_order_id' => $orderId,
        ]);
    }

    private function paidStatus(array $overrides = []): array
    {
        return array_merge([
            'id' => 'ord_1', 'isCompleted' => true, 'isCanceled' => false,
            'isRefunded' => false, 'finalAmount' => 150, 'currencyCode' => 'EUR',
        ], $overrides);
    }

    public function test_submit_booking_creates_pok_order_and_redirects_to_payment(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus());
        $room = $this->room(150);

        $res = $this->post(route('website.book.submit'), [
            'room_id' => $room->id,
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(4)->toDateString(),
            'first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@test.al',
            'phone' => '0691234567', 'adults' => 1,
        ]);

        $reservation = Reservation::first();
        $this->assertNotNull($reservation);
        $this->assertSame('pending', $reservation->status);
        $this->assertSame('ord_1', $reservation->pok_order_id);
        $res->assertRedirect(route('website.pay.show', $reservation->confirmation_token));
    }

    public function test_confirm_verifies_and_records_folio_payment(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus());
        $reservation = $this->pendingReservation($this->room());

        $this->post(route('website.pay.confirm', $reservation->confirmation_token))
            ->assertRedirect(route('website.booking.confirmation', $reservation->confirmation_token));

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->paid_at);
        $payment = Payment::where('reservation_id', $reservation->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('card', $payment->method);
        $this->assertSame('ord_1', $payment->pok_order_id);
        $this->assertEquals(150.0, (float) $payment->amount);
    }

    public function test_webhook_is_idempotent(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus());
        $reservation = $this->pendingReservation($this->room());

        $this->post(route('website.pay.webhook'), ['id' => 'ord_1'])->assertOk();
        $this->post(route('website.pay.webhook'), ['id' => 'ord_1'])->assertOk(); // duplicate/late

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertEquals(1, Payment::where('reservation_id', $reservation->id)->count()); // exactly one
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus(['finalAmount' => 999])); // POK says a different amount
        $reservation = $this->pendingReservation($this->room(), total: 150);

        $this->post(route('website.pay.webhook'), ['id' => 'ord_1'])->assertOk();

        $this->assertSame('pending', $reservation->fresh()->status);       // NOT confirmed
        $this->assertEquals(0, Payment::count());
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus(['currencyCode' => 'USD']));
        $reservation = $this->pendingReservation($this->room());

        $this->post(route('website.pay.webhook'), ['id' => 'ord_1'])->assertOk();

        $this->assertSame('pending', $reservation->fresh()->status);
        $this->assertEquals(0, Payment::count());
    }

    public function test_incomplete_order_is_rejected(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus(['isCompleted' => false]));
        $reservation = $this->pendingReservation($this->room());

        $this->post(route('website.pay.webhook'), ['id' => 'ord_1'])->assertOk();

        $this->assertSame('pending', $reservation->fresh()->status);
        $this->assertEquals(0, Payment::count());
    }

    public function test_cancelled_hold_is_not_resurrected_by_a_late_webhook(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus()); // POK reports paid, but the hold was already released
        $reservation = $this->pendingReservation($this->room());
        $reservation->update(['status' => 'cancelled']);

        $this->post(route('website.pay.webhook'), ['id' => 'ord_1'])->assertOk();

        $this->assertSame('cancelled', $reservation->fresh()->status); // R1: stays cancelled
        $this->assertEquals(0, Payment::count());
    }

    public function test_release_unpaid_holds_cancels_genuinely_unpaid_but_keeps_fresh(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus(['isCompleted' => false])); // POK confirms: NOT paid
        $room = $this->room();
        $old = $this->pendingReservation($room, 'ord_old');
        $old->forceFill(['created_at' => now()->subMinutes(40)])->save(); // abandoned
        $fresh = $this->pendingReservation($room, 'ord_fresh');           // just created

        $this->artisan('pok:release-unpaid')->assertExitCode(0);

        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame('pending', $fresh->fresh()->status);
    }

    public function test_release_SETTLES_a_paid_hold_instead_of_cancelling(): void
    {
        // THE critical fix: a guest paid (money captured) but both confirm paths missed the window.
        $this->configurePok();
        $this->fakePok($this->paidStatus()); // POK confirms: PAID
        $old = $this->pendingReservation($this->room(), 'ord_old');
        $old->forceFill(['created_at' => now()->subMinutes(40)])->save();

        $this->artisan('pok:release-unpaid')->assertExitCode(0);

        $this->assertSame('confirmed', $old->fresh()->status);          // settled, NOT cancelled
        $this->assertEquals(1, Payment::where('reservation_id', $old->id)->count());
    }

    public function test_release_SKIPS_when_pok_is_unreachable(): void
    {
        $this->configurePok();
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok']], 200),
            '*/sdk-orders/*' => Http::response('', 503), // POK down → getOrder throws
        ]);
        $old = $this->pendingReservation($this->room(), 'ord_old');
        $old->forceFill(['created_at' => now()->subMinutes(40)])->save();

        $this->artisan('pok:release-unpaid')->assertExitCode(0);

        $this->assertSame('pending', $old->fresh()->status); // fail-safe: never cancel on uncertainty
    }

    public function test_missing_finalAmount_keeps_hold_pending_and_does_not_silently_reject(): void
    {
        $this->configurePok();
        // Response-shape drift: no finalAmount → getOrder must THROW, not treat as 0.
        $this->fakePok(['id' => 'ord_1', 'isCompleted' => true, 'isCanceled' => false, 'isRefunded' => false, 'currencyCode' => 'EUR']);
        $reservation = $this->pendingReservation($this->room());

        $this->post(route('website.pay.webhook'), ['id' => 'ord_1'])->assertOk(); // swallowed + reported

        $this->assertSame('pending', $reservation->fresh()->status); // hold survives, not confirmed, not lost
        $this->assertEquals(0, Payment::count());
    }

    public function test_refund_after_confirmation_reverses_and_voids_payment(): void
    {
        $this->configurePok();
        $reservation = $this->pendingReservation($this->room());
        // Already settled earlier (confirmed + folio card payment on file).
        $reservation->update(['status' => 'confirmed', 'paid_at' => now()]);
        Payment::create([
            'reservation_id' => $reservation->id, 'amount' => 150, 'method' => 'card',
            'type' => 'payment', 'pok_order_id' => 'ord_1', 'currency' => 'EUR',
        ]);

        // Now POK reports the order refunded → the webhook must reverse it.
        $this->fakePok($this->paidStatus(['isRefunded' => true]));
        $this->post(route('website.pay.webhook'), ['id' => 'ord_1'])->assertOk();

        $this->assertSame('cancelled', $reservation->fresh()->status);         // room freed
        $this->assertTrue((bool) Payment::where('reservation_id', $reservation->id)->first()->is_voided);
    }

    public function test_payment_page_settles_an_already_paid_order_and_redirects_to_confirmation(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus()); // guest already paid; browser confirm was lost
        $reservation = $this->pendingReservation($this->room());

        $this->get(route('website.pay.show', $reservation->confirmation_token))
            ->assertRedirect(route('website.booking.confirmation', $reservation->confirmation_token));

        $this->assertSame('confirmed', $reservation->fresh()->status); // settled on page load, no re-charge
    }

    public function test_payment_page_prefills_guest_identity_so_the_card_form_is_not_re_asked(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus(['isCompleted' => false])); // open order → renders the form
        $room = $this->room();
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@test.al', 'phone' => '0691234567', 'nationality' => 'AL']);
        $reservation = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => User::factory()->create()->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(4)->toDateString(),
            'status' => 'pending', 'total_amount' => 150, 'adults' => 1, 'channel' => 'direct', 'pok_order_id' => 'ord_1',
        ]);

        $this->get(route('website.pay.show', $reservation->confirmation_token))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Website/BookingPayment')
                ->where('initialState.email', 'ana@test.al')
                ->where('initialState.holdersName', 'Ana Test')
                ->where('initialState.countryCode', 'AL')
                ->where('initialState.phoneNumber', '0691234567')
                ->where('openForPayment', true));
    }

    public function test_prefill_omits_a_non_alpha2_nationality_rather_than_sending_a_bad_country(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus(['isCompleted' => false]));
        $room = $this->room();
        $guest = Guest::create(['first_name' => 'Jon', 'last_name' => 'Doe', 'email' => 'jon@test.al', 'nationality' => 'ALB']); // legacy alpha-3
        $reservation = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => User::factory()->create()->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(4)->toDateString(),
            'status' => 'pending', 'total_amount' => 150, 'adults' => 1, 'channel' => 'direct', 'pok_order_id' => 'ord_1',
        ]);

        $this->get(route('website.pay.show', $reservation->confirmation_token))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('initialState.email', 'jon@test.al')
                ->missing('initialState.countryCode')); // 'ALB' is not alpha-2 → omitted, not sent wrong
    }

    public function test_repeat_booking_fills_an_existing_guests_EMPTY_nationality_so_country_prefills(): void
    {
        $this->configurePok();
        // One fake for the whole test: order is created but NOT paid, so pay.show renders the form.
        // (Http::fake stubs accumulate and the FIRST match wins — never re-fake mid-test.)
        $this->fakePok($this->paidStatus(['isCompleted' => false]));
        $room = $this->room(150);
        // Existing guest (matched by email) with NO nationality — the owner's exact repro.
        Guest::create(['first_name' => 'Marjus', 'last_name' => 'B', 'email' => 'repeat@test.al', 'phone' => '069000000']);

        $this->post(route('website.book.submit'), [
            'room_id' => $room->id,
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(4)->toDateString(),
            'first_name' => 'Marjus', 'last_name' => 'B', 'email' => 'repeat@test.al',
            'phone' => '069000000', 'nationality' => 'AL', 'adults' => 1,
        ]);

        $this->assertSame('AL', Guest::where('email', 'repeat@test.al')->value('nationality')); // blank got filled

        $reservation = Reservation::latest('id')->first();
        $this->get(route('website.pay.show', $reservation->confirmation_token))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('initialState.countryCode', 'AL'));
    }

    public function test_repeat_booking_never_overwrites_an_existing_guests_nationality_or_phone(): void
    {
        $this->configurePok();
        $this->fakePok($this->paidStatus());
        $room = $this->room(150);
        Guest::create(['first_name' => 'Ita', 'last_name' => 'G', 'email' => 'ita@test.al', 'phone' => '3551111', 'nationality' => 'IT']);

        $this->post(route('website.book.submit'), [
            'room_id' => $room->id,
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(4)->toDateString(),
            'first_name' => 'Ita', 'last_name' => 'G', 'email' => 'ita@test.al',
            'phone' => '3559999', 'nationality' => 'AL', 'adults' => 1, // attacker/typo tries to change them
        ]);

        $g = Guest::where('email', 'ita@test.al')->first();
        $this->assertSame('IT', $g->nationality);   // anti-tamper intact
        $this->assertSame('3551111', $g->phone);
    }
}
