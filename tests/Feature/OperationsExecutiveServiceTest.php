<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\Guest;
use App\Models\MaintenanceIssue;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\OperationsExecutiveService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsExecutiveServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_live_operational_snapshot(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 10:00:00');
        $user = User::factory()->create();
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test']);
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $arrivalRoom = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'cleaning']);
        $departureRoom = Room::create(['room_type_id' => $type->id, 'room_number' => '102', 'floor' => 1, 'status' => 'occupied']);
        $arrival = $this->reservation($arrivalRoom, $guest, $user, '2026-07-18', '2026-07-20', 'confirmed', 200);
        $departure = $this->reservation($departureRoom, $guest, $user, '2026-07-16', '2026-07-18', 'checked_in', 200);
        Payment::create(['reservation_id' => $departure->id, 'amount' => 200, 'method' => 'cash', 'type' => 'payment', 'created_by' => $user->id]);
        Payment::create(['reservation_id' => $departure->id, 'amount' => 25, 'method' => 'cash', 'type' => 'refund', 'created_by' => $user->id]);
        CleaningTask::create(['room_id' => $arrivalRoom->id, 'type' => 'checkout_clean', 'status' => 'pending', 'priority' => 'urgent']);
        PosOrder::create(['reservation_id' => $departure->id, 'status' => 'open', 'total_amount' => 10, 'created_by' => $user->id]);
        MaintenanceIssue::create(['room_id' => $arrivalRoom->id, 'reported_by' => $user->id, 'title' => 'AC', 'priority' => 'critical', 'status' => 'reported', 'due_at' => '2026-07-18 09:00:00']);
        MaintenanceIssue::create(['room_id' => $departureRoom->id, 'reported_by' => $user->id, 'title' => 'TV', 'priority' => 'critical', 'status' => 'resolved', 'due_at' => '2026-07-17 09:00:00']);

        $snapshot = app(OperationsExecutiveService::class)->snapshot();

        $this->assertSame(1, $snapshot['flow']['arrivals_remaining']);
        $this->assertSame(1, $snapshot['flow']['departures_remaining']);
        $this->assertSame(1, $snapshot['flow']['in_house_stays']);
        $this->assertSame(1, $snapshot['flow']['open_pos']);
        $this->assertSame(1, $snapshot['readiness']['attention']);
        $this->assertSame(2, $snapshot['maintenance']['open']);
        $this->assertSame(1, $snapshot['maintenance']['overdue']);
        $this->assertSame(1, $snapshot['maintenance']['critical']);
        $this->assertSame(25.0, $snapshot['flow']['departure_balance']);
        $this->assertFalse(collect($snapshot['actions'])->contains(fn (array $action) => $action['kind'] === 'readiness' && $action['room'] === '102'));
        $this->assertTrue(collect($snapshot['actions'])->contains('kind', 'departure'));
        $this->assertTrue(collect($snapshot['actions'])->contains('kind', 'maintenance'));
        $this->assertFalse(collect($snapshot['actions'])->contains(fn (array $action) => ($action['title'] ?? null) === 'TV'));

        $restricted = app(OperationsExecutiveService::class)->snapshot(false, false, false);
        $this->assertFalse(collect($restricted['actions'])->contains('kind', 'maintenance'));
        CarbonImmutable::setTestNow();
    }

    private function reservation(Room $room, Guest $guest, User $user, string $checkIn, string $checkOut, string $status, float $total): Reservation
    {
        return Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => $checkIn, 'check_out_date' => $checkOut, 'status' => $status,
            'total_amount' => $total, 'adults' => 2, 'channel' => 'direct',
        ]);
    }
}
