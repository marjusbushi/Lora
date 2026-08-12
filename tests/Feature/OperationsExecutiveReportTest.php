<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OperationsExecutiveReportTest extends TestCase
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

    public function test_overdue_arrival_and_departure_are_counted_and_lead_the_action_queue(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);

        // Guest was due 2 days ago and nobody resolved the booking.
        Reservation::create([
            'room_id' => $this->makeRoom($type, '101')->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->subDays(2)->toDateString(), 'check_out_date' => today()->addDay()->toDateString(),
            'status' => 'confirmed', 'total_amount' => 100, 'adults' => 1, 'channel' => 'direct',
        ]);
        // Guest should have left yesterday and is still checked in.
        Reservation::create([
            'room_id' => $this->makeRoom($type, '102', 'occupied')->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->subDays(3)->toDateString(), 'check_out_date' => today()->subDay()->toDateString(),
            'status' => 'checked_in', 'total_amount' => 100, 'adults' => 1, 'channel' => 'direct',
        ]);
        // Already resolved as no-show — must NOT count as overdue.
        Reservation::create([
            'room_id' => $this->makeRoom($type, '103')->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->subDays(5)->toDateString(), 'check_out_date' => today()->subDays(3)->toDateString(),
            'status' => 'confirmed', 'no_show_at' => now()->subDays(4), 'total_amount' => 100, 'adults' => 1, 'channel' => 'direct',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.operationsExecutive'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/OperationsExecutive')
                ->where('analytics.flow.arrivals_overdue', 1)
                ->where('analytics.flow.departures_overdue', 1)
                ->where('analytics.actions_truncated', 0)
                ->where('analytics.actions', function ($actions) {
                    $actions = collect($actions);
                    $overdue = $actions->take(2)->pluck('kind')->sort()->values()->all();

                    return $overdue === ['overdue_arrival', 'overdue_departure']
                        && $actions->take(2)->every(fn ($action) => $action['severity'] === 'error' && $action['days_overdue'] >= 1);
                }));
    }

    public function test_quiet_day_reports_zero_overdue_and_zero_truncated(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);

        // A normal on-time arrival today only.
        Reservation::create([
            'room_id' => $this->makeRoom($type, '101')->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(), 'check_out_date' => today()->addDays(2)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 100, 'adults' => 1, 'channel' => 'direct',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.operationsExecutive'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/OperationsExecutive')
                ->where('analytics.flow.arrivals_overdue', 0)
                ->where('analytics.flow.departures_overdue', 0)
                ->where('analytics.actions_truncated', 0));
    }

    public function test_action_surplus_is_reported_as_truncated_not_silently_dropped(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);

        // 20 unresolved past arrivals: the overdue list caps at 6 rows, the
        // other 14 must be declared in actions_truncated, never swallowed.
        foreach (range(1, 20) as $i) {
            Reservation::create([
                'room_id' => $this->makeRoom($type, (string) (200 + $i))->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
                'check_in_date' => today()->subDays(2)->toDateString(), 'check_out_date' => today()->addDay()->toDateString(),
                'status' => 'confirmed', 'total_amount' => 100, 'adults' => 1, 'channel' => 'direct',
            ]);
        }

        $this->actingAs($admin)
            ->get(route('reports.operationsExecutive'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/OperationsExecutive')
                ->where('analytics.flow.arrivals_overdue', 20)
                ->where('analytics.actions', fn ($actions) => collect($actions)->where('kind', 'overdue_arrival')->count() === 6
                    && count($actions) <= 15)
                ->where('analytics.actions_truncated', fn ($truncated) => (int) $truncated >= 14));
    }

    public function test_payload_carries_dynamic_currency_contract(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('reports.operationsExecutive'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/OperationsExecutive')
                ->has('pricingCurrency')
                ->has('baseToPricingRate')
                ->has('currency'));
    }
}
