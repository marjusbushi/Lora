<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\MaintenanceIssue;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\MaintenanceDowntimeService;
use App\Services\Reporting\ReportingPeriod;
use App\Services\Reporting\RoomTypePerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomTypePerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_uses_stay_dates_and_sellable_inventory_per_room_type(): void
    {
        $user = User::factory()->create();
        $standard = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $deluxe = RoomType::create(['name' => 'Deluxe', 'base_price' => 150, 'max_occupancy' => 2, 'amenities' => []]);
        $standardRoom = Room::create(['room_type_id' => $standard->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $deluxeRoom = Room::create(['room_type_id' => $deluxe->id, 'room_number' => '201', 'floor' => 2, 'status' => 'maintenance']);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);

        Reservation::create([
            'room_id' => $standardRoom->id,
            'guest_id' => $guest->id,
            'created_by' => $user->id,
            'check_in_date' => '2026-06-30',
            'check_out_date' => '2026-07-03',
            'status' => 'checked_out',
            'total_amount' => 300,
            'adults' => 1,
            'children' => 0,
            'channel' => 'direct',
        ]);

        $issue = MaintenanceIssue::create([
            'room_id' => $deluxeRoom->id,
            'reported_by' => $user->id,
            'title' => 'Blocked room',
            'room_blocked' => true,
            'status' => 'in_progress',
        ]);
        $issue->forceFill([
            'created_at' => '2026-07-01 08:00:00',
            'updated_at' => '2026-07-01 08:00:00',
        ])->saveQuietly();

        $this->assertTrue($issue->fresh()->room_blocked);
        $this->assertCount(1, app(MaintenanceDowntimeService::class)->forRooms(
            [$deluxeRoom->id],
            new ReportingPeriod('2026-07-01', '2026-07-02'),
        ));

        $summary = app(RoomTypePerformanceService::class)
            ->summary(new ReportingPeriod('2026-07-01', '2026-07-02'));

        $standardRow = collect($summary['rows'])->firstWhere('type_id', $standard->id);
        $deluxeRow = collect($summary['rows'])->firstWhere('type_id', $deluxe->id);

        $this->assertSame(200.0, $standardRow['room_revenue']);
        $this->assertSame(2, $standardRow['occupied_room_nights']);
        $this->assertSame(2, $standardRow['sellable_room_nights']);
        $this->assertSame(100.0, $standardRow['occupancy']);
        $this->assertSame(100.0, $standardRow['adr']);
        $this->assertSame(100.0, $standardRow['revpar']);

        $this->assertSame(0, $deluxeRow['sellable_room_nights']);
        $this->assertSame(2, $deluxeRow['blocked_room_nights']);
        $this->assertSame(200.0, $summary['kpis']['room_revenue']);
        $this->assertSame(100.0, $summary['kpis']['occupancy']);
    }
}
