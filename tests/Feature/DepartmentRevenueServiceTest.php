<?php

namespace Tests\Feature;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
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
        PosOrderPayment::create([
            'pos_order_id' => $refunded->id, 'direction' => 'out', 'method' => 'card',
            'amount' => 20, 'paid_at' => '2026-07-03 12:00:00', 'created_by' => $user->id,
        ]);
        PosOrderPayment::create([
            'pos_order_id' => $refunded->id, 'direction' => 'out', 'method' => 'cash',
            'amount' => 30, 'paid_at' => '2026-07-03 12:01:00', 'created_by' => $user->id,
        ]);
        PosOrder::create([
            'status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 40,
            'business_date' => '2026-07-03', 'paid_at' => '2026-07-03 13:00:00', 'created_by' => $user->id,
        ]);
        PosOrder::create([
            'reservation_id' => $reservation->id, 'status' => 'completed', 'payment_method' => 'cash',
            'total_amount' => 25, 'business_date' => '2026-06-30', 'paid_at' => '2026-06-30 13:00:00',
            'refunded_at' => '2026-07-04 10:00:00', 'created_by' => $user->id,
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

        $this->assertSame(284.21, $report['current']['summary']['rooms']);
        $this->assertSame(16.32, $report['current']['summary']['pos']);
        $this->assertSame(28.42, $report['current']['summary']['other']);
        $this->assertSame(328.95, $report['current']['summary']['total']);
        $this->assertSame(-7.37, collect($report['current']['daily'])->firstWhere('date', '2026-07-03')['pos']);
        $this->assertSame(-23.68, collect($report['current']['daily'])->firstWhere('date', '2026-07-04')['pos']);
        $this->assertSame(86.4, collect($report['current']['departments'])->firstWhere('department', 'rooms')['share']);
        $this->assertNull($report['changes']['total']);
    }

    public function test_folio_extras_count_at_base_currency_value(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $guest = Guest::create(['first_name' => 'Base', 'last_name' => 'Guest']);
        $reservation = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => '2026-07-01', 'check_out_date' => '2026-07-02', 'status' => 'checked_out',
            'total_amount' => 100, 'adults' => 1, 'children' => 0, 'channel' => 'direct',
        ]);
        FolioItem::create([
            'reservation_id' => $reservation->id, 'description' => 'Spa në EUR',
            'amount' => 30, 'exchange_rate' => 100, 'type' => 'extra', 'charge_date' => '2026-07-01',
        ]);

        $report = app(DepartmentRevenueService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-02'));

        $this->assertSame(3000.0, $report['summary']['other']);
    }
}
