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
        Payment::create(['reservation_id' => $reservation->id, 'amount' => 30, 'method' => 'cash', 'created_by' => $user->id, 'is_voided' => true]);

        $summary = app(OutstandingBalanceService::class)->summary();

        $this->assertSame(1, $summary['count']);
        $this->assertSame(70.0, $summary['total']);
    }
}
