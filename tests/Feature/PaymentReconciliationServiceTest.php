<?php

namespace Tests\Feature;

use App\Models\FinancePayment;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\FinanceLedger;
use App\Services\Reporting\PaymentReconciliationService;
use App\Services\Reporting\ReportingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_separates_real_collections_and_detects_reconciliation_issues(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $guest = Guest::create(['first_name' => 'Payment', 'last_name' => 'Guest']);
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $user->id,
            'check_in_date' => '2026-07-01',
            'check_out_date' => '2026-07-05',
            'status' => 'checked_in',
            'total_amount' => 300,
            'adults' => 1,
            'children' => 0,
            'channel' => 'direct',
        ]);

        $cash = Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 100,
            'method' => 'cash',
            'type' => 'payment',
            'created_by' => $user->id,
        ]);
        $cash->timestamps = false;
        $cash->forceFill(['created_at' => '2026-07-02 10:00:00', 'updated_at' => '2026-07-02 10:00:00'])->saveQuietly();

        $card = Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 60,
            'method' => 'card',
            'type' => 'payment',
            'created_by' => $user->id,
        ]);
        FinancePayment::where('sourceable_type', Payment::class)->where('sourceable_id', $card->id)->delete();
        $card->timestamps = false;
        $card->forceFill(['created_at' => '2026-07-02 11:00:00', 'updated_at' => '2026-07-02 11:00:00'])->saveQuietly();

        $ota = Payment::create([
            'reservation_id' => $reservation->id,
            'amount' => 80,
            'method' => 'ota',
            'type' => 'payment',
            'created_by' => $user->id,
        ]);
        $ota->timestamps = false;
        $ota->forceFill(['created_at' => '2026-07-02 11:30:00', 'updated_at' => '2026-07-02 11:30:00'])->saveQuietly();

        foreach ([['refund', 10, false], ['payment', 5, true]] as [$paymentType, $amount, $voided]) {
            $payment = Payment::create([
                'reservation_id' => $reservation->id,
                'amount' => $amount,
                'method' => 'cash',
                'type' => $paymentType,
                'is_voided' => $voided,
                'created_by' => $user->id,
            ]);
            $payment->timestamps = false;
            $payment->forceFill(['created_at' => '2026-07-02 12:00:00', 'updated_at' => '2026-07-02 12:00:00'])->saveQuietly();
        }

        $shift = PosShift::create([
            'user_id' => $user->id,
            'status' => 'open',
            'opening_float' => 100,
            'opened_at' => '2026-07-03 08:00:00',
        ]);
        $cashOrder = $this->posOrder($user, $reservation, 40, '2026-07-03', $shift->id);
        $this->posPayment($cashOrder, $shift, $user, 'cash', 40);
        $cardOrder = $this->posOrder($user, $reservation, 30, '2026-07-03', $shift->id);
        $cardTender = $this->posPayment($cardOrder, $shift, $user, 'card', 30);
        $this->posPayment($cardOrder, $shift, $user, 'card', 10, 'out', $cardTender->id);
        $roomOrder = $this->posOrder($user, $reservation, 20, '2026-07-03', $shift->id);
        $this->posPayment($roomOrder, $shift, $user, 'room_charge', 20);

        $legacy = PosOrder::create([
            'reservation_id' => $reservation->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 15,
            'created_by' => $user->id,
        ]);
        $legacy->timestamps = false;
        $legacy->forceFill(['created_at' => '2026-07-04 12:00:00', 'updated_at' => '2026-07-04 12:00:00'])->saveQuietly();

        $shift->update([
            'status' => 'closed',
            'closed_at' => '2026-07-03 18:00:00',
            'closed_by' => $user->id,
            'expected_cash' => 140,
            'counted_cash' => 145,
            'over_short' => 5,
            'cash_sales' => 40,
        ]);

        $analytics = app(PaymentReconciliationService::class)
            ->summary(new ReportingPeriod('2026-07-01', '2026-07-05'));

        $this->assertSame(225.0, $analytics['summary']['collected']);
        $this->assertSame(145.0, $analytics['summary']['cash']);
        $this->assertSame(80.0, $analytics['summary']['card']);
        $this->assertSame(20.0, $analytics['summary']['room_charge']);
        $this->assertSame(20.0, $analytics['summary']['refunds']);
        $this->assertSame(5.0, $analytics['summary']['voided']);
        $this->assertSame(7, $analytics['summary']['expected_sources']);
        $this->assertSame(6, $analytics['summary']['matched_sources']);
        $this->assertSame(85.7, $analytics['summary']['reconciliation_rate']);
        $this->assertSame(7, $analytics['summary']['transaction_count']);
        $this->assertSame(60.0, $analytics['summary']['unposted_total']);
        $this->assertSame(3, $analytics['summary']['issues_count']);
        $this->assertEqualsCanonicalizing(
            ['cash_variance', 'legacy_settlement_date', 'missing_ledger'],
            collect($analytics['issues'])->pluck('type')->all(),
        );
        $this->assertSame(60.0, collect($analytics['daily'])->firstWhere('date', '2026-07-03')['total']);
    }

    private function posOrder(User $user, Reservation $reservation, float $amount, string $date, int $shiftId): PosOrder
    {
        return PosOrder::create([
            'reservation_id' => $reservation->id,
            'status' => 'completed',
            'total_amount' => $amount,
            'created_by' => $user->id,
            'pos_shift_id' => $shiftId,
            'paid_at' => "{$date} 12:00:00",
            'business_date' => $date,
        ]);
    }

    private function posPayment(
        PosOrder $order,
        PosShift $shift,
        User $user,
        string $method,
        float $amount,
        string $direction = 'in',
        ?int $refundedFrom = null,
    ): PosOrderPayment {
        $payment = PosOrderPayment::create([
            'pos_order_id' => $order->id,
            'pos_shift_id' => $shift->id,
            'direction' => $direction,
            'method' => $method,
            'amount' => $amount,
            'refunded_from_id' => $refundedFrom,
            'paid_at' => '2026-07-03 12:00:00',
            'created_by' => $user->id,
        ]);
        app(FinanceLedger::class)->recordPosOrderPayment($payment);

        return $payment;
    }
}
