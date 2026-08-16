<?php

namespace Tests\Feature;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\OutstandingBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutstandingBalanceV2ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_states_and_real_vs_exposure_split(): void
    {
        $user = User::factory()->create();
        $room = $this->room();
        $guest = Guest::create(['first_name' => 'State', 'last_name' => 'Guest']);

        $future = $this->reservation($room, $guest, $user, 'confirmed', today()->addDay(), today()->addDays(3), 100);
        $arrivingToday = $this->reservation($room, $guest, $user, 'confirmed', today(), today()->addDays(2), 200);
        $inHouse = $this->reservation($room, $guest, $user, 'checked_in', today()->subDay(), today()->addDay(), 300);
        $overdue = $this->reservation($room, $guest, $user, 'checked_out', today()->subDays(3), today()->subDay(), 400);
        $dueToday = $this->reservation($room, $guest, $user, 'confirmed', today()->subDay(), today(), 500);

        $report = app(OutstandingBalanceService::class)->analytics();
        $byId = collect($report['rows'])->keyBy('id');

        $this->assertSame('future_arrival', $byId[$future->id]['state']);
        $this->assertSame('arriving_today', $byId[$arrivingToday->id]['state']);
        $this->assertSame('in_house', $byId[$inHouse->id]['state']);
        $this->assertSame('due', $byId[$overdue->id]['state']);
        $this->assertSame(1, $byId[$overdue->id]['days_overdue']);
        $this->assertSame('1_7', $byId[$overdue->id]['bucket']);
        $this->assertSame('due', $byId[$dueToday->id]['state']);
        $this->assertSame(0, $byId[$dueToday->id]['days_overdue']);

        $this->assertSame(1200.0, $report['summary']['real_total']);
        $this->assertSame(3, $report['summary']['real_count']);
        $this->assertSame(300.0, $report['summary']['exposure_total']);
        $this->assertSame(2, $report['summary']['exposure_count']);
        $this->assertSame(
            $report['summary']['total'],
            round($report['summary']['real_total'] + $report['summary']['exposure_total'], 2),
        );
    }

    public function test_as_of_reconstruction_caps_payments_charges_and_bookings(): void
    {
        $user = User::factory()->create();
        $room = $this->room();
        $guest = Guest::create(['first_name' => 'AsOf', 'last_name' => 'Guest']);
        $asOf = CarbonImmutable::parse('2026-06-30');

        // R1: stay ended Jun 25; partially paid before as-of, fully paid after.
        $r1 = $this->reservation($room, $guest, $user, 'checked_out', '2026-06-20', '2026-06-25', 500, '2026-06-01 10:00:00');
        FolioItem::create(['reservation_id' => $r1->id, 'description' => 'Bar brenda', 'amount' => 40, 'type' => 'bar', 'charge_date' => '2026-06-26']);
        FolioItem::create(['reservation_id' => $r1->id, 'description' => 'Bar korrik', 'amount' => 50, 'type' => 'bar', 'charge_date' => '2026-07-02']);
        $this->payment($r1, $user, 200, '2026-06-28 12:00:00');
        $this->payment($r1, $user, 300, '2026-07-05 12:00:00');

        // R2: booked after the as-of date — must not exist in the past view.
        $this->reservation($room, $guest, $user, 'checked_out', '2026-07-12', '2026-07-15', 700, '2026-07-10 09:00:00');

        // R3: NULL booked_at is kept (migrated rows), 20 days overdue at as-of.
        $r3 = $this->reservation($room, $guest, $user, 'checked_out', '2026-06-08', '2026-06-10', 100, null);

        $past = app(OutstandingBalanceService::class)->analytics($asOf);
        $pastById = collect($past['rows'])->keyBy('id');

        $this->assertCount(2, $past['rows']);
        // Gross 500 + 40 (Jul 2 charge excluded), paid 200 (Jul 5 payment excluded).
        $this->assertSame(340.0, $pastById[$r1->id]['balance']);
        $this->assertSame('1_7', $pastById[$r1->id]['bucket']);
        $this->assertSame(100.0, $pastById[$r3->id]['balance']);
        $this->assertSame('8_30', $pastById[$r3->id]['bucket']);

        // The live view sees everything: R1 nearly settled, R2 present.
        $live = app(OutstandingBalanceService::class)->analytics();
        $liveById = collect($live['rows'])->keyBy('id');
        $this->assertCount(3, $live['rows']);
        $this->assertSame(90.0, $liveById[$r1->id]['balance']);
    }

    public function test_arrival_window_filters_by_check_in_edges(): void
    {
        $user = User::factory()->create();
        $room = $this->room();
        $guest = Guest::create(['first_name' => 'Window', 'last_name' => 'Guest']);

        $before = $this->reservation($room, $guest, $user, 'confirmed', today()->addDay(), today()->addDays(2), 100);
        $fromEdge = $this->reservation($room, $guest, $user, 'confirmed', today()->addDays(5), today()->addDays(6), 200);
        $toEdge = $this->reservation($room, $guest, $user, 'confirmed', today()->addDays(10), today()->addDays(11), 300);

        $report = app(OutstandingBalanceService::class)->analytics(
            null,
            today()->addDays(5)->toDateString(),
            today()->addDays(10)->toDateString(),
        );
        $ids = collect($report['rows'])->pluck('id');

        $this->assertNotContains($before->id, $ids);
        $this->assertContains($fromEdge->id, $ids);
        $this->assertContains($toEdge->id, $ids);
        $this->assertSame(500.0, $report['summary']['total']);
    }

    private function room(): Room
    {
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);

        return Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
    }

    private function reservation(Room $room, Guest $guest, User $user, string $status, $checkIn, $checkOut, float $total, ?string $bookedAt = 'default'): Reservation
    {
        $reservation = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => is_string($checkIn) ? $checkIn : $checkIn->toDateString(),
            'check_out_date' => is_string($checkOut) ? $checkOut : $checkOut->toDateString(),
            'status' => $status, 'total_amount' => $total, 'adults' => 1, 'children' => 0, 'channel' => 'direct',
        ]);
        // The model stamps booked_at = now() when empty; force the scenario's value.
        $reservation->timestamps = false;
        $reservation->forceFill(['booked_at' => $bookedAt === 'default' ? now()->subDays(30) : $bookedAt])->saveQuietly();

        return $reservation;
    }

    private function payment(Reservation $reservation, User $user, float $amount, string $createdAt): Payment
    {
        $payment = Payment::create([
            'reservation_id' => $reservation->id, 'amount' => $amount, 'method' => 'cash', 'created_by' => $user->id,
        ]);
        $payment->timestamps = false;
        $payment->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $payment;
    }
}
