<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ExecutiveDashboardV2Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeRoom(RoomType $type, string $number, string $status = 'available'): Room
    {
        return Room::create(['room_type_id' => $type->id, 'room_number' => $number, 'floor' => 1, 'status' => $status]);
    }

    /** Whole floats round-trip through JSON as ints depending on serialize_precision — compare numerically. */
    private function numeric(float $expected): \Closure
    {
        return fn ($actual) => is_numeric($actual) && abs((float) $actual - $expected) < 0.005;
    }

    public function test_channel_table_reports_base_currency_not_reservation_currency(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $room = $this->makeRoom($type, '101');
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);

        // The Saturn bug: a €100 OTA stay at rate 98.7 was shown as 100 on a
        // Lek dashboard. The channel table must report the *_base columns.
        Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(), 'check_out_date' => today()->addDays(2)->toDateString(),
            'status' => 'checked_in', 'adults' => 2, 'channel' => 'booking.com',
            'currency' => 'EUR', 'exchange_rate' => 98.7,
            'total_amount' => 100, 'commission_amount' => 10,
        ]);

        $this->actingAs($admin)
            ->get(route('reports.executive', [
                'from' => today()->toDateString(),
                'to' => today()->addDays(2)->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Executive')
                ->where('channels.0.channel', 'booking.com')
                ->where('channels.0.nights', 2)
                ->where('channels.0.revenue', $this->numeric(9870.0))
                ->where('channels.0.commission', $this->numeric(987.0))
                ->where('channels.0.net', $this->numeric(8883.0)));
    }

    public function test_sellable_inventory_excludes_maintenance_rooms(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $occupied = $this->makeRoom($type, '201', 'occupied');
        $this->makeRoom($type, '202');
        $this->makeRoom($type, '203', 'maintenance');
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);

        Reservation::create([
            'room_id' => $occupied->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(), 'check_out_date' => today()->addDay()->toDateString(),
            'status' => 'checked_in', 'total_amount' => 100, 'adults' => 1, 'channel' => 'direct',
        ]);

        // 1 occupied of 2 sellable rooms = 50% — a maintenance room is not
        // inventory a guest could take (pre-fix: 1 of 3 = 33.3%).
        $this->actingAs($admin)
            ->get(route('reports.executive', [
                'from' => today()->toDateString(),
                'to' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Executive')
                ->where('analytics.current.daily.'.today()->toDateString().'.sellable_room_nights', 2)
                ->where('analytics.current.kpis.occupancy', $this->numeric(50.0)));
    }

    public function test_payload_carries_operations_strip_and_outstanding_split(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);

        // Arrival today, unpaid, stay not finished → "future" money, 3 pax.
        Reservation::create([
            'room_id' => $this->makeRoom($type, '301')->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(), 'check_out_date' => today()->addDays(2)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 300, 'adults' => 2, 'children' => 1, 'channel' => 'direct',
        ]);
        // In house, unpaid, leaves tomorrow → due at checkout.
        Reservation::create([
            'room_id' => $this->makeRoom($type, '302')->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->subDay()->toDateString(), 'check_out_date' => today()->addDay()->toDateString(),
            'status' => 'checked_in', 'total_amount' => 200, 'adults' => 2, 'children' => 0, 'channel' => 'direct',
        ]);
        // Checked out 5 days ago, unpaid → overdue; only this one may alert.
        Reservation::create([
            'room_id' => $this->makeRoom($type, '303')->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->subDays(8)->toDateString(), 'check_out_date' => today()->subDays(5)->toDateString(),
            'status' => 'checked_out', 'total_amount' => 100, 'adults' => 1, 'children' => 0, 'channel' => 'direct',
        ]);

        $rooms = Room::orderBy('room_number')->get();
        CleaningTask::create(['room_id' => $rooms[0]->id, 'type' => 'checkout_clean', 'status' => 'pending']);
        CleaningTask::create(['room_id' => $rooms[1]->id, 'type' => 'checkout_clean', 'status' => 'in_progress']);
        CleaningTask::create(['room_id' => $rooms[2]->id, 'type' => 'checkout_clean', 'status' => 'completed']);

        $this->actingAs($admin)
            ->get(route('reports.executive'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Executive')
                ->where('operations.arrivals.count', 1)
                ->where('operations.arrivals.pax', 3)
                ->where('operations.departures.count', 0)
                ->where('operations.in_house.count', 1)
                ->where('operations.in_house.pax', 2)
                ->where('operations.rooms_to_clean', 2)
                ->where('outstandingSplit.overdue.count', 1)
                ->where('outstandingSplit.overdue.total', $this->numeric(100.0))
                ->where('outstandingSplit.due_at_checkout.count', 1)
                ->where('outstandingSplit.due_at_checkout.total', $this->numeric(200.0))
                ->where('outstandingSplit.future.count', 1)
                ->where('outstandingSplit.future.total', $this->numeric(300.0))
                ->where('outstanding.count', 3)
                ->where('outstanding.total', $this->numeric(600.0))
                ->where('occupancyAlertPct', 85)
                ->has('pricingCurrency')
                ->has('baseToPricingRate')
                ->where('alerts.0.kind', 'outstanding')
                ->where('alerts.0.value', $this->numeric(100.0))
                ->where('alerts.0.count', 1)
                ->count('alerts', 1));
    }

    public function test_occupancy_alert_threshold_comes_from_settings(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $roomA = $this->makeRoom($type, '401');
        $this->makeRoom($type, '402');
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);

        // One of two rooms booked today → 50% forecast peak. Below the 85
        // default (test above shows no demand alert); above a 40 threshold.
        Reservation::create([
            'room_id' => $roomA->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(), 'check_out_date' => today()->addDay()->toDateString(),
            'status' => 'checked_in', 'total_amount' => 100, 'adults' => 1, 'channel' => 'direct',
        ]);
        Setting::set('reports.occupancy_alert_pct', 40, 'number');

        $this->actingAs($admin)
            ->get(route('reports.executive'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Executive')
                ->where('occupancyAlertPct', 40)
                ->where('alerts.0.kind', 'demand')
                ->where('alerts.0.value', $this->numeric(50.0)));
    }

    public function test_invalid_date_filters_are_rejected_with_422_not_500(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from(route('reports.executive'))
            ->get(route('reports.executive', ['from' => '2026-07-31', 'to' => '2026-07-01']))
            ->assertRedirect(route('reports.executive'))
            ->assertSessionHasErrors('to');

        $this->actingAs($admin)->from(route('reports.executive'))
            ->get(route('reports.executive', ['from' => 'not-a-date', 'to' => '2026-07-31']))
            ->assertRedirect(route('reports.executive'))
            ->assertSessionHasErrors('from');
    }
}
