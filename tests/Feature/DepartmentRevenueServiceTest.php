<?php

namespace Tests\Feature;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\DepartmentRevenueService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentRevenueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_net_recognized_revenue_without_double_counting_room_charges(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $guest = Guest::create(['first_name' => 'Revenue', 'last_name' => 'Guest']);
        $reservation = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => '2026-07-01', 'check_out_date' => '2026-07-04', 'status' => 'checked_out',
            'total_amount' => 300, 'adults' => 1, 'children' => 0, 'channel' => 'direct',
        ]);
        FolioItem::create(['reservation_id' => $reservation->id, 'description' => 'Loyalty', 'amount' => 20, 'type' => 'discount', 'charge_date' => '2026-07-02']);
        FolioItem::create(['reservation_id' => $reservation->id, 'description' => 'Spa', 'amount' => 30, 'type' => 'spa', 'charge_date' => '2026-07-02']);

        $refunded = PosOrder::create([
            'reservation_id' => $reservation->id, 'status' => 'completed', 'payment_method' => 'card',
            'total_amount' => 50, 'business_date' => '2026-07-02', 'paid_at' => '2026-07-02 12:00:00',
            'refunded_at' => '2026-07-03 12:00:00', 'created_by' => $user->id,
        ]);
        FolioItem::create([
            'reservation_id' => $reservation->id, 'pos_order_id' => $refunded->id,
            'description' => 'Room charge', 'amount' => 50, 'type' => 'restaurant', 'charge_date' => '2026-07-02',
        ]);
        PosOrder::create([
            'status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 40,
            'business_date' => '2026-07-03', 'paid_at' => '2026-07-03 13:00:00', 'created_by' => $user->id,
        ]);
        $cancelled = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => '2026-07-02', 'check_out_date' => '2026-07-03', 'status' => 'cancelled',
            'total_amount' => 90, 'adults' => 1, 'children' => 0, 'channel' => 'direct',
        ]);
        FolioItem::create([
            'reservation_id' => $cancelled->id, 'description' => 'Cancelled extra',
            'amount' => 99, 'type' => 'spa', 'charge_date' => '2026-07-02',
        ]);

        $report = app(DepartmentRevenueService::class)->withComparison(new ReportingPeriod('2026-07-01', '2026-07-04'));

        $this->assertSame(280.0, $report['current']['summary']['rooms']);
        $this->assertSame(40.0, $report['current']['summary']['pos']);
        $this->assertSame(30.0, $report['current']['summary']['other']);
        $this->assertSame(350.0, $report['current']['summary']['total']);
        $this->assertSame(80.0, collect($report['current']['departments'])->firstWhere('department', 'rooms')['share']);
        $this->assertNull($report['changes']['total']);
    }
}
