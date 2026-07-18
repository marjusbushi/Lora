<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\GuestSegmentationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestSegmentationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_mutually_exclusive_actionable_segments(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);

        $this->stays($room, $user, 'VIP', 2, 500, '2026-06-01');
        $this->stays($room, $user, 'Loyal', 3, 100, '2026-05-01');
        $this->stays($room, $user, 'Returning', 2, 100, '2026-04-01');
        $this->stays($room, $user, 'New', 1, 5000, '2026-07-01');
        $this->stays($room, $user, 'Dormant', 1, 100, '2024-01-01');

        $report = app(GuestSegmentationService::class)->summary();
        $byName = collect($report['guests'])->keyBy('guest');

        $this->assertSame(5, $report['summary']['total_guests']);
        $this->assertSame(1, $report['summary']['vip_guests']);
        $this->assertSame(1000.0, $report['summary']['vip_threshold']);
        $this->assertSame(1, $report['summary']['dormant_guests']);
        $this->assertSame('vip', $byName['VIP Test']['segment_360']);
        $this->assertSame('loyal', $byName['Loyal Test']['segment_360']);
        $this->assertSame('returning', $byName['Returning Test']['segment_360']);
        $this->assertSame('new', $byName['New Test']['segment_360']);
        $this->assertSame('dormant', $byName['Dormant Test']['segment_360']);
        $this->assertSame(5, collect($report['segments'])->sum('guests'));
        $dormant = app(GuestSegmentationService::class)->summary('dormant');
        $this->assertSame('dormant', $dormant['active_segment']);
        $this->assertCount(1, $dormant['guests']);
        $this->assertSame('dormant', $dormant['guests'][0]['segment_360']);
        CarbonImmutable::setTestNow();
    }

    private function stays(Room $room, User $user, string $name, int $count, float $value, string $lastDate): void
    {
        $guest = Guest::create(['first_name' => $name, 'last_name' => 'Test']);
        $last = CarbonImmutable::parse($lastDate);
        for ($index = $count - 1; $index >= 0; $index--) {
            $checkIn = $last->subMonths($index);
            Reservation::create([
                'guest_id' => $guest->id, 'room_id' => $room->id, 'created_by' => $user->id,
                'check_in_date' => $checkIn->toDateString(), 'check_out_date' => $checkIn->addDay()->toDateString(),
                'status' => 'checked_out', 'total_amount' => $value, 'adults' => 1, 'channel' => 'direct',
            ]);
        }
    }
}
