<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Payment;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\ReportingPeriod;
use App\Services\Reporting\ShiftReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_sealed_shift_totals_and_flags_inconsistent_snapshots(): void
    {
        $user = User::factory()->create();
        $this->shift($user, '2026-07-10 08:00:00', '2026-07-10 16:00:00', 50, 100, 60, 20, 150, 148, -2, 180);
        $this->shift($user, '2026-07-11 08:00:00', '2026-07-11 16:00:00', 20, 80, 40, 10, 100, 105, 5, 999);
        $this->shift($user, '2026-07-12 08:00:00', null, 0, 20, 0, 0, 20, 20, 0, 20, 'open');
        $this->shift($user, '2026-06-01 08:00:00', '2026-06-01 16:00:00', 0, 30, 0, 0, 30, 30, 0, 30);

        $report = app(ShiftReportService::class)
            ->summary(new ReportingPeriod('2026-07-10', '2026-07-11'));

        $this->assertCount(2, $report['shifts']);
        $this->assertFalse($report['shifts'][0]['is_consistent']);
        $this->assertTrue($report['shifts'][1]['is_consistent']);
        $this->assertSame(180.0, $report['totals']['cash']);
        $this->assertSame(100.0, $report['totals']['card']);
        $this->assertSame(30.0, $report['totals']['room_charge']);
        $this->assertSame(1179.0, $report['totals']['total']);
        $this->assertSame(3.0, $report['totals']['over_short']);
        $this->assertSame(1, $report['totals']['inconsistent_count']);
    }

    public function test_pms_cash_window_attribution_and_suspect_entry_boundary(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $guest = Guest::create(['first_name' => 'Cash', 'last_name' => 'Guest']);
        $reservation = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $user->id,
            'check_in_date' => '2026-07-10', 'check_out_date' => '2026-07-13', 'status' => 'checked_in',
            'total_amount' => 300, 'adults' => 1, 'children' => 0, 'channel' => 'direct',
        ]);

        // Counted exactly 10× expected → suspect; 9.999× → not suspect; expected 0 → never suspect.
        $this->shift($user, '2026-07-10 08:00:00', '2026-07-10 16:00:00', 50, 50, 0, 0, 100, 1000, 900, 50);
        $this->shift($user, '2026-07-11 08:00:00', '2026-07-11 16:00:00', 50, 50, 0, 0, 100, 999.99, 899.99, 50);
        $this->shift($user, '2026-07-12 08:00:00', '2026-07-12 16:00:00', 0, 0, 0, 0, 0, 500, 500, 0);

        foreach ([
            [30, 'cash', null, false, '2026-07-10 12:00:00'],   // inside shift A → counted
            [20, 'cash', null, false, '2026-07-10 20:00:00'],   // between shifts → no shift
            [40, 'card', null, false, '2026-07-10 12:30:00'],   // card → excluded
            [15, 'cash', null, true, '2026-07-10 13:00:00'],    // voided → excluded
            [10, 'cash', 'refund', false, '2026-07-10 14:00:00'], // refund → excluded
            [25, 'cash', 'deposit', false, '2026-07-11 12:00:00'], // deposit inside B → counted
        ] as [$amount, $method, $paymentType, $voided, $createdAt]) {
            $payment = Payment::create([
                'reservation_id' => $reservation->id, 'amount' => $amount, 'method' => $method,
                'type' => $paymentType, 'is_voided' => $voided, 'created_by' => $user->id,
            ]);
            $payment->timestamps = false;
            $payment->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
        }

        $report = app(ShiftReportService::class)->summary(new ReportingPeriod('2026-07-10', '2026-07-12'));

        $this->assertCount(3, $report['shifts']);
        [$shiftC, $shiftB, $shiftA] = $report['shifts'];
        $this->assertSame(30.0, $shiftA['pms_cash']);
        $this->assertTrue($shiftA['suspect_entry']);
        $this->assertSame(25.0, $shiftB['pms_cash']);
        $this->assertFalse($shiftB['suspect_entry']);
        $this->assertSame(0.0, $shiftC['pms_cash']);
        $this->assertFalse($shiftC['suspect_entry']);
        $this->assertSame(55.0, $report['totals']['pms_cash']);
        $this->assertSame(1, $report['totals']['suspect_count']);
    }

    private function shift(
        User $user,
        string $openedAt,
        ?string $closedAt,
        float $opening,
        float $cash,
        float $card,
        float $room,
        float $expected,
        float $counted,
        float $overShort,
        float $total,
        string $status = 'closed',
    ): PosShift {
        return PosShift::create([
            'user_id' => $user->id,
            'status' => $status,
            'opening_float' => $opening,
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'over_short' => $overShort,
            'cash_sales' => $cash,
            'card_sales' => $card,
            'room_charge_sales' => $room,
            'total_sales' => $total,
        ]);
    }
}
