<?php

namespace Tests\Feature;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\DiscountRefundCashFlowService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountRefundCashFlowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_discounts_refunds_and_leakage_shares(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $guest = Guest::create(['first_name' => 'Cash', 'last_name' => 'Guest']);
        $reservation = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => '2026-07-01', 'check_out_date' => '2026-07-05', 'status' => 'checked_in',
            'total_amount' => 300, 'rate_before_discount' => 315, 'direct_discount_amount' => 15,
            'booked_at' => '2026-07-02 09:00:00', 'adults' => 1, 'children' => 0, 'channel' => 'direct',
        ]);

        FolioItem::create([
            'reservation_id' => $reservation->id, 'description' => 'Loyalty',
            'amount' => 20, 'type' => 'discount', 'charge_date' => '2026-07-02',
        ]);
        PosOrder::create([
            'status' => 'completed', 'payment_method' => 'cash', 'subtotal_amount' => 50,
            'discount_amount' => 5, 'discount_reason' => 'Happy hour', 'total_amount' => 45,
            'business_date' => '2026-07-03', 'paid_at' => '2026-07-03 12:00:00', 'created_by' => $user->id,
        ]);
        $refund = Payment::create([
            'reservation_id' => $reservation->id, 'amount' => 10, 'method' => 'cash',
            'type' => 'refund', 'created_by' => $user->id,
        ]);
        $refund->timestamps = false;
        $refund->forceFill(['created_at' => '2026-07-04 10:00:00', 'updated_at' => '2026-07-04 10:00:00'])->saveQuietly();

        // A real collection so the refund share has a denominator: 18 / 50 = 36%.
        $collection = Payment::create([
            'reservation_id' => $reservation->id, 'amount' => 50, 'method' => 'cash', 'created_by' => $user->id,
        ]);
        $collection->timestamps = false;
        $collection->forceFill(['created_at' => '2026-07-03 10:00:00', 'updated_at' => '2026-07-03 10:00:00'])->saveQuietly();

        $pos = PosOrder::create([
            'status' => 'completed', 'payment_method' => 'card', 'total_amount' => 30,
            'business_date' => '2026-07-04', 'paid_at' => '2026-07-04 12:00:00', 'created_by' => $user->id,
        ]);
        PosOrderPayment::create([
            'pos_order_id' => $pos->id, 'direction' => 'out', 'method' => 'card',
            'amount' => 8, 'paid_at' => '2026-07-04 13:00:00', 'created_by' => $user->id,
        ]);
        FolioItem::create([
            'reservation_id' => $reservation->id, 'pos_order_id' => $pos->id,
            'description' => 'POS refund reversal', 'amount' => -8,
            'type' => 'discount', 'charge_date' => '2026-07-04',
        ]);

        $report = app(DiscountRefundCashFlowService::class)->summary(new ReportingPeriod('2026-07-01', '2026-07-31'));

        $this->assertSame(40.0, $report['summary']['discounts']);
        $this->assertSame(18.0, $report['summary']['refunds']);
        $this->assertSame(3, $report['summary']['discount_count']);
        $this->assertSame(50.0, $report['summary']['collections']);
        $this->assertSame(36.0, $report['summary']['refund_share']);
        $this->assertGreaterThan(0, $report['summary']['revenue_net']);
        $this->assertSame(
            round($report['summary']['discounts'] / $report['summary']['revenue_net'] * 100, 1),
            $report['summary']['discount_share'],
        );
        $this->assertCount(5, $report['activity']);
        $this->assertSame(35.0, collect($report['discount_sources'])->firstWhere('source', 'pms')['amount']);
        $this->assertFalse(collect($report['activity'])->contains('reason', 'POS refund reversal'));
        // The cash-flow half is gone — the payload no longer carries ledger keys.
        $this->assertArrayNotHasKey('inflow', $report['summary']);
        $this->assertArrayNotHasKey('net_cash_flow', $report['summary']);
        $this->assertArrayNotHasKey('daily', $report);
    }

    public function test_shares_are_null_when_denominators_are_zero(): void
    {
        $report = app(DiscountRefundCashFlowService::class)->summary(new ReportingPeriod('2026-03-01', '2026-03-31'));

        $this->assertSame(0.0, $report['summary']['discounts']);
        $this->assertSame(0.0, $report['summary']['revenue_net']);
        $this->assertSame(0.0, $report['summary']['collections']);
        $this->assertNull($report['summary']['discount_share']);
        $this->assertNull($report['summary']['refund_share']);
    }
}
