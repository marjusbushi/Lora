<?php

namespace Tests\Feature;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\MaintenanceIssue;
use App\Models\PosOrder;
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

    public function test_executive_revenue_reconciles_with_room_folio_pos_and_refund_events(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Deluxe', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '201', 'floor' => 2, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Audit', 'last_name' => 'Guest']);
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $user->id,
            'check_in_date' => '2026-07-01',
            'check_out_date' => '2026-07-03',
            'status' => 'checked_out',
            'total_amount' => 200,
            'adults' => 1,
            'children' => 0,
            'channel' => 'direct',
        ]);

        FolioItem::create(['reservation_id' => $reservation->id, 'description' => 'Ulje', 'amount' => 10, 'type' => 'discount', 'charge_date' => '2026-07-01']);
        FolioItem::create(['reservation_id' => $reservation->id, 'description' => 'Spa', 'amount' => 30, 'type' => 'spa', 'charge_date' => '2026-07-01']);
        PosOrder::create([
            'status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 50,
            'business_date' => '2026-07-01', 'paid_at' => '2026-07-01 18:00:00', 'created_by' => $user->id,
        ]);
        PosOrder::create([
            'status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 20,
            'business_date' => '2026-06-30', 'paid_at' => '2026-06-30 18:00:00',
            'refunded_at' => '2026-07-02 10:00:00', 'created_by' => $user->id,
        ]);

        $summary = app(HotelKpiService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-02'));

        $this->assertSame(191.3, $summary['kpis']['room_revenue']);
        $this->assertSame(30.0, $summary['kpis']['pos_revenue']);
        $this->assertSame(28.7, $summary['kpis']['other_revenue']);
        $this->assertSame(250.0, $summary['kpis']['total_revenue']);
        $this->assertSame(95.65, $summary['kpis']['adr']);
        $this->assertSame(95.65, $summary['kpis']['revpar']);
        $this->assertSame(125.0, $summary['kpis']['trevpar']);
        $this->assertSame(174.35, $summary['daily']['2026-07-01']['total_revenue']);
        $this->assertSame(75.65, $summary['daily']['2026-07-02']['total_revenue']);
    }

    public function test_occupancy_comparison_is_reported_in_percentage_points(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '301', 'floor' => 3, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Trend', 'last_name' => 'Guest']);

        foreach ([
            ['2026-07-01', '2026-07-02', 100],
            ['2026-07-03', '2026-07-05', 200],
        ] as [$checkIn, $checkOut, $total]) {
            Reservation::create([
                'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
                'check_in_date' => $checkIn, 'check_out_date' => $checkOut, 'status' => 'checked_out',
                'total_amount' => $total, 'adults' => 1, 'children' => 0, 'channel' => 'direct',
            ]);
        }

        $analytics = app(HotelKpiService::class)->withComparisons(new ReportingPeriod('2026-07-03', '2026-07-04'));

        $this->assertSame(100.0, $analytics['current']['kpis']['occupancy']);
        $this->assertSame(50.0, $analytics['previous_period']['kpis']['occupancy']);
        $this->assertSame(50.0, $analytics['changes']['occupancy']);
    }
}
