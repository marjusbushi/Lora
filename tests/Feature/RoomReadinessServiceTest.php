<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\RoomReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_complete_room_board_without_a_hidden_limit(): void
    {
        User::factory()->create();
        $type = RoomType::create(['name' => 'Large property', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        foreach (range(1, 101) as $number) {
            $this->room($type, str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'available');
        }

        $report = app(RoomReadinessService::class)->snapshot();

        $this->assertSame(101, $report['summary']['total_rooms']);
        $this->assertCount(101, $report['rooms']);
    }

    public function test_it_prioritizes_live_arrival_readiness_and_turnovers(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 10:00:00');
        $user = User::factory()->create(['name' => 'Housekeeper']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test']);
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $ready = $this->room($type, '101', 'available');
        $cleaning = $this->room($type, '102', 'cleaning');
        $turnover = $this->room($type, '103', 'occupied');
        $maintenance = $this->room($type, '104', 'maintenance');
        $this->room($type, '105', 'available');

        $this->arrival($ready, $guest, $user);
        $this->arrival($cleaning, $guest, $user);
        $this->arrival($turnover, $guest, $user);
        $this->arrival($maintenance, $guest, $user);
        $this->departure($turnover, $guest, $user);
        CleaningTask::create([
            'room_id' => $cleaning->id, 'assigned_to' => $user->id,
            'type' => 'checkout_clean', 'status' => 'in_progress', 'priority' => 'urgent',
        ]);
        CleaningTask::create([
            'room_id' => $turnover->id, 'assigned_to' => $user->id,
            'type' => 'checkout_clean', 'status' => 'pending', 'priority' => 'urgent',
        ]);

        $report = app(RoomReadinessService::class)->snapshot();

        $this->assertSame(4, $report['summary']['arrivals_remaining']);
        $this->assertSame(1, $report['summary']['ready_arrivals']);
        $this->assertSame(25.0, $report['summary']['ready_rate']);
        $this->assertSame(3, $report['summary']['attention']);
        $this->assertSame(1, $report['summary']['cleaning']);
        $this->assertSame(1, $report['summary']['maintenance']);
        $this->assertSame(1, $report['summary']['turnovers']);
        $this->assertSame('ready', collect($report['rooms'])->firstWhere('room_number', '101')['state']);
        $this->assertSame('cleaning_for_arrival', collect($report['rooms'])->firstWhere('room_number', '102')['state']);
        $this->assertSame('turnover', collect($report['rooms'])->firstWhere('room_number', '103')['state']);

        $restricted = app(RoomReadinessService::class)->snapshot(false, false);
        $this->assertNull(collect($restricted['rooms'])->firstWhere('room_number', '101')['arrival']['guest']);
        $this->assertNull(collect($restricted['rooms'])->firstWhere('room_number', '102')['cleaning']['assignee']);
        CarbonImmutable::setTestNow();
    }

    private function room(RoomType $type, string $number, string $status): Room
    {
        return Room::create(['room_type_id' => $type->id, 'room_number' => $number, 'floor' => 1, 'status' => $status]);
    }

    private function arrival(Room $room, Guest $guest, User $user): Reservation
    {
        return Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => '2026-07-18', 'check_out_date' => '2026-07-20',
            'status' => 'confirmed', 'total_amount' => 200, 'adults' => 2, 'channel' => 'direct',
        ]);
    }

    private function departure(Room $room, Guest $guest, User $user): Reservation
    {
        return Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => '2026-07-16', 'check_out_date' => '2026-07-18',
            'status' => 'checked_in', 'total_amount' => 200, 'adults' => 2, 'channel' => 'direct',
        ]);
    }
}
