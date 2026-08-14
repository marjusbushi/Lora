<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Reporting\BudgetTargetService;
use App\Services\Reporting\OutstandingBalanceService;
use App\Services\Reporting\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutiveDashboardServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_target_is_prorated_for_the_selected_period(): void
    {
        Budget::create([
            'period' => '2026-07',
            'revenue_target' => 3100,
            'adr_target' => 120,
            'occupancy_target' => 80,
            'revpar_target' => 96,
        ]);

        $target = app(BudgetTargetService::class)
            ->forPeriod(new ReportingPeriod('2026-07-01', '2026-07-10'));

        $this->assertTrue($target['has_budget']);
        $this->assertSame(1000.0, $target['revenue_target']);
        $this->assertSame(120.0, $target['adr_target']);
        $this->assertSame(80.0, $target['occupancy_target']);
        $this->assertSame(96.0, $target['revpar_target']);
    }

    public function test_outstanding_balance_includes_folio_adjustments_and_non_voided_payments(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $user->id,
            'check_in_date' => '2026-07-01',
            'check_out_date' => '2026-07-02',
            'status' => 'checked_out',
            'total_amount' => 100,
            'adults' => 1,
            'children' => 0,
            'channel' => 'direct',
        ]);

        FolioItem::create(['reservation_id' => $reservation->id, 'description' => 'Bar', 'amount' => 20, 'type' => 'bar', 'charge_date' => '2026-07-01']);
        FolioItem::create(['reservation_id' => $reservation->id, 'description' => 'Ulje', 'amount' => 10, 'type' => 'discount', 'charge_date' => '2026-07-01']);
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 40, 'method' => 'cash', 'created_by' => $user->id]);
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 10, 'method' => 'cash', 'created_by' => $user->id, 'type' => 'refund']);
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 5, 'method' => 'cash', 'created_by' => $user->id, 'type' => 'writeoff']);
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 30, 'method' => 'cash', 'created_by' => $user->id, 'is_voided' => true]);

        $summary = app(OutstandingBalanceService::class)->summary();

        $this->assertSame(1, $summary['count']);
        $this->assertSame(75.0, $summary['total']);
    }

    public function test_outstanding_analytics_groups_balances_into_actionable_aging_buckets(): void
    {
        $user = User::factory()->create();
        $type = RoomType::create(['name' => 'Standard', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
        $guest = Guest::create(['first_name' => 'Aging', 'last_name' => 'Guest']);
        $stays = [
            ['room' => '201', 'checkout' => '2026-07-25', 'paid' => 0, 'status' => 'confirmed'],
            ['room' => '202', 'checkout' => '2026-07-17', 'paid' => 20, 'status' => 'checked_out'],
            ['room' => '203', 'checkout' => '2026-07-10', 'paid' => 30, 'status' => 'checked_out'],
            ['room' => '204', 'checkout' => '2026-06-10', 'paid' => 50, 'status' => 'checked_out'],
            ['room' => '205', 'checkout' => '2026-05-01', 'paid' => 60, 'status' => 'checked_out'],
        ];

        foreach ($stays as $index => $stay) {
            $room = Room::create([
                'room_type_id' => $type->id,
                'room_number' => $stay['room'],
                'floor' => 2,
                'status' => 'available',
            ]);
            $reservation = Reservation::create([
                'room_id' => $room->id,
                'guest_id' => $guest->id,
                'created_by' => $user->id,
                'check_in_date' => CarbonImmutable::parse($stay['checkout'])->subDay()->toDateString(),
                'check_out_date' => $stay['checkout'],
                'status' => $stay['status'],
                'total_amount' => 100,
                'adults' => 1,
                'children' => 0,
                'channel' => $index % 2 === 0 ? 'direct' : 'booking.com',
            ]);
            // The historical as-of below (2026-07-18) reconstructs the ledger:
            // bookings and payments stamped "now" would vanish from the past view.
            $this->backdate($reservation, '2026-04-01 10:00:00');

            if ($stay['paid'] > 0) {
                $this->backdate(Payment::create([
                    'reservation_id' => $reservation->id,
                    'amount' => $stay['paid'],
                    'method' => 'cash',
                    'created_by' => $user->id,
                ]), '2026-07-15 12:00:00');
            }
        }

        $paidRoom = Room::create([
            'room_type_id' => $type->id,
            'room_number' => '206',
            'floor' => 2,
            'status' => 'available',
        ]);
        $paidStay = Reservation::create([
            'room_id' => $paidRoom->id,
            'guest_id' => $guest->id,
            'created_by' => $user->id,
            'check_in_date' => '2026-07-01',
            'check_out_date' => '2026-07-02',
            'status' => 'checked_out',
            'total_amount' => 100,
            'adults' => 1,
            'channel' => 'direct',
        ]);
        $this->backdate($paidStay, '2026-04-01 10:00:00');
        $this->backdate(Payment::create([
            'reservation_id' => $paidStay->id,
            'amount' => 100,
            'method' => 'cash',
            'created_by' => $user->id,
        ]), '2026-07-15 12:00:00');
        $refundedStay = Reservation::whereHas('room', fn ($query) => $query->where('room_number', '202'))->firstOrFail();
        $this->backdate(Payment::create([
            'reservation_id' => $refundedStay->id,
            'amount' => 10,
            'method' => 'cash',
            'type' => 'refund',
            'created_by' => $user->id,
        ]), '2026-07-15 12:30:00');

        $analytics = app(OutstandingBalanceService::class)->analytics(CarbonImmutable::parse('2026-07-18'));
        $buckets = collect($analytics['buckets'])->keyBy('key');

        $this->assertSame(5, $analytics['summary']['count']);
        $this->assertSame(350.0, $analytics['summary']['total']);
        $this->assertSame(250.0, $analytics['summary']['overdue_total']);
        $this->assertSame(90.0, $analytics['summary']['critical_total']);
        $this->assertSame(41.7, $analytics['summary']['collection_rate']);
        $this->assertSame(600.0, $analytics['summary']['gross']);
        $this->assertSame(250.0, $analytics['summary']['paid']);
        $this->assertSame(100.0, $buckets['not_due']['amount']);
        $this->assertSame(90.0, $buckets['1_7']['amount']);
        $this->assertSame(70.0, $buckets['8_30']['amount']);
        $this->assertSame(50.0, $buckets['31_60']['amount']);
        $this->assertSame(40.0, $buckets['61_plus']['amount']);
        $this->assertSame('61_plus', $analytics['rows'][0]['bucket']);
        $this->assertSame(10.0, collect($analytics['rows'])->firstWhere('room', '202')['paid']);
    }

    private function backdate(Reservation|Payment $model, string $timestamp): void
    {
        $model->timestamps = false;
        $fill = ['created_at' => $timestamp, 'updated_at' => $timestamp];
        if ($model instanceof Reservation) {
            $fill['booked_at'] = $timestamp;
        }
        $model->forceFill($fill)->saveQuietly();
    }
}
