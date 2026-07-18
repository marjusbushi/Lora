<?php

namespace Tests\Feature;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\GuestMovementService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_unifies_arrivals_departures_and_live_in_house_stays(): void
    {
        $user = User::factory()->create();
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'phone' => '123']);
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $arrival = $this->reservation($this->room($type, '101'), $guest, $user, 'confirmed', '2026-07-10', '2026-07-12', 100);
        $departure = $this->reservation($this->room($type, '102'), $guest, $user, 'checked_out', '2026-07-08', '2026-07-10', 80);
        $this->reservation($this->room($type, '103'), $guest, $user, 'checked_in', '2026-07-09', '2026-08-01', 500);
        $completedArrival = $this->reservation($this->room($type, '104'), $guest, $user, 'checked_out', '2026-07-10', '2026-07-11', 60);

        FolioItem::create(['reservation_id' => $arrival->id, 'description' => 'Spa', 'amount' => 20, 'type' => 'spa', 'charge_date' => '2026-07-10']);
        FolioItem::create(['reservation_id' => $arrival->id, 'description' => 'Discount', 'amount' => 5, 'type' => 'discount', 'charge_date' => '2026-07-10']);
        Payment::create(['reservation_id' => $arrival->id, 'amount' => 40, 'method' => 'cash', 'created_by' => $user->id]);
        Payment::create(['reservation_id' => $arrival->id, 'amount' => 10, 'method' => 'cash', 'type' => 'refund', 'created_by' => $user->id]);
        Payment::create(['reservation_id' => $arrival->id, 'amount' => 5, 'method' => 'other', 'type' => 'writeoff', 'created_by' => $user->id]);
        Payment::create(['reservation_id' => $completedArrival->id, 'amount' => 80, 'method' => 'cash', 'created_by' => $user->id]);
        PosOrder::create(['reservation_id' => $departure->id, 'status' => 'open', 'total_amount' => 10, 'created_by' => $user->id]);

        $report = app(GuestMovementService::class)->summary(new ReportingPeriod('2026-07-10', '2026-07-11'));

        $this->assertSame(2, $report['summary']['arrivals']['count']);
        $this->assertSame(2, $report['summary']['departures']['count']);
        $this->assertSame(1, $report['summary']['in_house']['count']);
        $this->assertSame(80.0, collect($report['arrivals'])->firstWhere('id', $arrival->id)['balance']);
        $this->assertSame(80.0, $report['summary']['arrivals']['balance']);
        $this->assertSame(20.0, $report['summary']['arrivals']['credit']);
        $this->assertTrue(collect($report['arrivals'])->contains('id', $completedArrival->id));
        $this->assertSame(1, $report['departures'][0]['open_pos_count']);
        $this->assertSame(1, $report['summary']['departures']['open_pos']);
    }

    private function room(RoomType $type, string $number): Room
    {
        return Room::create(['room_type_id' => $type->id, 'room_number' => $number, 'floor' => 1, 'status' => 'available']);
    }

    private function reservation(Room $room, Guest $guest, User $user, string $status, string $checkIn, string $checkOut, float $total): Reservation
    {
        return Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => $checkIn, 'check_out_date' => $checkOut, 'status' => $status,
            'total_amount' => $total, 'adults' => 2, 'children' => 0, 'channel' => 'direct',
        ]);
    }
}
