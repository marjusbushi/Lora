<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\PokPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Multi-room direct bookings (Booking.com model): the guest picks TYPOLOGIES + quantities,
 * the server assigns concrete rooms atomically, ONE POK order pays the whole group (held by
 * the primary reservation), and settle/reverse/release act on the group as a unit.
 */
class BookingGroupTest extends TestCase
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
    private function fakePok(array $orderStatus, float $createdAmount = 300): void
    {
        Http::fake([
            '*/auth/sdk/login' => Http::response(['data' => ['accessToken' => 'tok', 'expiresIn' => 3600000]], 200),
            '*/sdk-orders/*' => Http::response(['data' => ['sdkOrder' => $orderStatus]], 200),
            '*/sdk-orders' => Http::response(['data' => ['sdkOrder' => ['id' => 'ord_g1', 'finalAmount' => $createdAmount, 'currencyCode' => 'EUR']]], 200),
        ]);
    }

    /** A typology with N physical rooms. */
    private function type(string $name, int $roomCount, float $base = 150, int $maxOcc = 3): RoomType
    {
        $type = RoomType::create(['name' => $name, 'base_price' => $base, 'max_occupancy' => $maxOcc]);
        foreach (range(1, $roomCount) as $i) {
            Room::create(['room_number' => "{$name}-{$i}", 'room_type_id' => $type->id, 'floor' => 1, 'status' => 'available']);
        }

        return $type;
    }

    private function submit(array $selections, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('website.book.submit'), array_merge([
            'selections' => $selections,
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
            'first_name' => 'Ana', 'last_name' => 'Grupi', 'email' => 'grup@test.al',
            'phone' => '0691234567', 'adults' => 4, 'children' => 0,
        ], $overrides));
    }

    public function test_check_availability_returns_one_row_per_typology_with_available_count(): void
    {
        $deluxe = $this->type('Deluxe', 3);
        $this->type('Standard', 2, 90, 2);

        $response = $this->postJson(route('website.book.check'), [
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $rows = collect($response->json('room_types'));
        $this->assertCount(2, $rows);
        $this->assertSame(3, $rows->firstWhere('room_type_id', $deluxe->id)['available_count']);

        // Demand-signal semantics unchanged: results_count counts ROOMS, not typologies.
        $this->assertSame(5, \App\Models\WebsiteSearchLog::first()->results_count);
    }

    public function test_group_booking_creates_one_reservation_per_room_with_shared_group_id(): void
    {
        $this->configurePok();
        $this->fakePok(['id' => 'ord_g1', 'isCompleted' => false, 'isCanceled' => false, 'isRefunded' => false, 'finalAmount' => 600, 'currencyCode' => 'EUR']);
        $deluxe = $this->type('Deluxe', 3); // base 150 × 2 nights = 300/room

        $this->submit([['room_type_id' => $deluxe->id, 'quantity' => 2]])->assertRedirect();

        $reservations = Reservation::orderBy('id')->get();
        $this->assertCount(2, $reservations);
        $this->assertNotNull($reservations[0]->booking_group_id);
        $this->assertSame($reservations[0]->booking_group_id, $reservations[1]->booking_group_id);
        $this->assertNotSame($reservations[0]->room_id, $reservations[1]->room_id, 'each member must claim a DIFFERENT room');

        // Only the PRIMARY holds the POK order (reservations.pok_order_id is UNIQUE).
        $this->assertSame('ord_g1', $reservations[0]->pok_order_id);
        $this->assertNull($reservations[1]->pok_order_id);

        // Every room needs an adult: 4 adults over 2 rooms.
        $this->assertGreaterThanOrEqual(1, (int) $reservations[0]->adults);
        $this->assertGreaterThanOrEqual(1, (int) $reservations[1]->adults);
        $this->assertSame(4, (int) $reservations->sum('adults'));

        // The POK order was created with the GROUP total (2 × 300).
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sdk-orders')
                && $request->method() === 'POST'
                && (float) data_get($request->data(), 'amount') === 600.0;
        });
    }

    public function test_single_room_booking_stays_ungrouped(): void
    {
        $deluxe = $this->type('Deluxe', 2);

        $this->submit([['room_type_id' => $deluxe->id, 'quantity' => 1]], ['adults' => 2])->assertRedirect();

        $this->assertNull(Reservation::sole()->booking_group_id);
    }

    public function test_insufficient_rooms_rolls_the_whole_group_back(): void
    {
        $deluxe = $this->type('Deluxe', 2);
        $standard = $this->type('Standard', 1, 90, 2);

        // Deluxe ×2 fits, Standard ×2 does NOT (only 1 room) → nothing may persist.
        $this->submit([
            ['room_type_id' => $deluxe->id, 'quantity' => 2],
            ['room_type_id' => $standard->id, 'quantity' => 2],
        ], ['adults' => 4])->assertSessionHasErrors('selections');

        $this->assertSame(0, Reservation::count());
    }

    public function test_capacity_and_adults_per_room_are_validated(): void
    {
        $deluxe = $this->type('Deluxe', 2, 150, 2); // 2 rooms × 2 persons = 4 max

        $this->submit([['room_type_id' => $deluxe->id, 'quantity' => 2]], ['adults' => 3, 'children' => 2])
            ->assertSessionHasErrors('selections'); // 5 guests > 4 capacity

        $this->submit([['room_type_id' => $deluxe->id, 'quantity' => 2]], ['adults' => 1, 'children' => 1])
            ->assertSessionHasErrors('selections'); // 1 adult < 2 rooms

        $this->assertSame(0, Reservation::count());
    }

    /** @return array{0: Reservation, 1: Reservation} pending group [primary, member] totalling 600 */
    private function pendingGroup(string $orderId = 'ord_g1'): array
    {
        $deluxe = $this->type('Deluxe', 2);
        $guest = Guest::create(['first_name' => 'G', 'last_name' => 'X', 'email' => 'g@x.al']);
        $creator = User::factory()->create();
        $rooms = Room::orderBy('id')->get();
        $group = (string) \Illuminate\Support\Str::uuid();

        $make = fn (Room $room, ?string $pok) => Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $creator->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'pending',
            'total_amount' => 300,
            'adults' => 2,
            'channel' => 'direct',
            'booking_group_id' => $group,
            'pok_order_id' => $pok,
        ]);

        return [$make($rooms[0], $orderId), $make($rooms[1], null)];
    }

    public function test_group_settle_confirms_all_members_and_records_a_payment_each(): void
    {
        $this->configurePok();
        $this->fakePok(['id' => 'ord_g1', 'isCompleted' => true, 'isCanceled' => false, 'isRefunded' => false, 'finalAmount' => 600, 'currencyCode' => 'EUR']);
        [$primary, $member] = $this->pendingGroup();

        $this->assertTrue(app(PokPayments::class)->settle($primary));

        $this->assertSame('confirmed', $primary->fresh()->status);
        $this->assertSame('confirmed', $member->fresh()->status);
        $this->assertNotNull($member->fresh()->paid_at);

        // One folio payment PER member, each with its own share, all on the same order.
        $payments = Payment::orderBy('reservation_id')->get();
        $this->assertCount(2, $payments);
        $this->assertEquals([300.0, 300.0], $payments->map(fn ($p) => (float) $p->amount)->all());
        $this->assertSame(['ord_g1', 'ord_g1'], $payments->pluck('pok_order_id')->all());

        // Idempotent: a second settle changes nothing and records nothing extra.
        $this->assertFalse(app(PokPayments::class)->settle($primary->fresh()));
        $this->assertCount(2, Payment::all());
    }

    public function test_group_settle_refuses_wrong_amount(): void
    {
        $this->configurePok();
        // Order paid 300, but the group is worth 600 → R2 amount guard must refuse EVERYTHING.
        $this->fakePok(['id' => 'ord_g1', 'isCompleted' => true, 'isCanceled' => false, 'isRefunded' => false, 'finalAmount' => 300, 'currencyCode' => 'EUR']);
        [$primary, $member] = $this->pendingGroup();

        $this->assertFalse(app(PokPayments::class)->settle($primary));

        $this->assertSame('pending', $primary->fresh()->status);
        $this->assertSame('pending', $member->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_group_settle_throws_on_partially_released_group_instead_of_confirming_half(): void
    {
        $this->configurePok();
        $this->fakePok(['id' => 'ord_g1', 'isCompleted' => true, 'isCanceled' => false, 'isRefunded' => false, 'finalAmount' => 600, 'currencyCode' => 'EUR']);
        [$primary, $member] = $this->pendingGroup();
        $member->update(['status' => 'cancelled']); // staff released one room before the money landed

        $this->expectException(\RuntimeException::class);

        try {
            app(PokPayments::class)->settle($primary);
        } finally {
            // Fail-loud contract: NOTHING was confirmed and no payment recorded.
            $this->assertSame('pending', $primary->fresh()->status);
            $this->assertSame(0, Payment::count());
        }
    }

    public function test_group_reverse_cancels_all_members_and_voids_their_payments(): void
    {
        $this->configurePok();
        $this->fakePok(['id' => 'ord_g1', 'isCompleted' => true, 'isCanceled' => false, 'isRefunded' => false, 'finalAmount' => 600, 'currencyCode' => 'EUR']);
        [$primary, $member] = $this->pendingGroup();
        app(PokPayments::class)->settle($primary);

        $this->assertTrue(app(PokPayments::class)->reverse($primary->fresh(), 'refund'));

        $this->assertSame('cancelled', $primary->fresh()->status);
        $this->assertSame('cancelled', $member->fresh()->status);
        $this->assertSame(2, Payment::where('is_voided', true)->count());
    }

    public function test_release_unpaid_holds_frees_the_whole_group(): void
    {
        $this->configurePok();
        $this->fakePok(['id' => 'ord_g1', 'isCompleted' => false, 'isCanceled' => true, 'isRefunded' => false, 'finalAmount' => 600, 'currencyCode' => 'EUR']);
        [$primary, $member] = $this->pendingGroup();
        Reservation::whereIn('id', [$primary->id, $member->id])->update(['created_at' => now()->subHour()]);

        $this->artisan('pok:release-unpaid', ['--tenant' => $primary->tenant_id])
            ->assertSuccessful();

        $this->assertSame('cancelled', $primary->fresh()->status, 'stale unpaid primary must be released');
        $this->assertSame('cancelled', $member->fresh()->status, 'group member must be released WITH the primary');
    }
}
