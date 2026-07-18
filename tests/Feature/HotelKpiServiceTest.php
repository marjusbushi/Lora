<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\MaintenanceIssue;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\HotelKpiService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelKpiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_uses_stay_dates_and_subtracts_blocked_inventory(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $occupiedRoom = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $blockedRoom = Room::create(['room_type_id' => $type->id, 'room_number' => '102', 'floor' => 1, 'status' => 'maintenance']);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);

        Reservation::create([
            'room_id' => $occupiedRoom->id,
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
            'room_id' => $blockedRoom->id,
            'reported_by' => $user->id,
            'title' => 'Blocked room',
            'room_blocked' => true,
            'status' => 'in_progress',
        ]);
        $issue->forceFill([
            'created_at' => '2026-07-01 08:00:00',
            'updated_at' => '2026-07-01 08:00:00',
        ])->saveQuietly();

        $summary = app(HotelKpiService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-02'));

        $this->assertSame(200.0, $summary['kpis']['room_revenue']);
        $this->assertSame(2, $summary['kpis']['occupied_room_nights']);
        $this->assertSame(2, $summary['kpis']['sellable_room_nights']);
        $this->assertSame(100.0, $summary['kpis']['occupancy']);
        $this->assertSame(100.0, $summary['kpis']['adr']);
        $this->assertSame(100.0, $summary['kpis']['revpar']);
    }
}
