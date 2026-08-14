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

class GuestMovementsReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_malformed_dates_are_rejected_with_422_not_500(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('reports.guestMovements', ['from' => 'abc']))
            ->assertSessionHasErrors(['from']);

        $this->actingAs($admin)
            ->getJson(route('reports.guestMovements', ['from' => 'abc']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);

        // Malformed "to" used to reach Carbon::parse inside the window-cap
        // closure and 500 — the guard must keep it a clean 422.
        $this->actingAs($admin)
            ->getJson(route('reports.guestMovements', ['to' => 'abc']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);

        $this->actingAs($admin)
            ->getJson(route('reports.guestMovements', ['from' => 'abc', 'to' => today()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_window_over_367_days_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson(route('reports.guestMovements', ['from' => '2026-01-01', 'to' => '2027-06-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);

        // The default "from" is the start of the month — a lone far-future
        // "to" must not slip past the cap.
        $this->actingAs($admin)
            ->getJson(route('reports.guestMovements', ['to' => '2100-01-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_to_before_from_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('reports.guestMovements', ['from' => '2026-08-12', 'to' => '2026-08-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_valid_and_default_windows_render_with_data_and_currency_contract(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);
        Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(), 'check_out_date' => today()->addDays(2)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 100, 'adults' => 2, 'channel' => 'direct',
        ]);

        // Explicit valid window.
        $this->actingAs($admin)
            ->get(route('reports.guestMovements', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/GuestMovements')
                ->where('analytics.summary.arrivals.count', 1)
                ->has('pricingCurrency')
                ->has('baseToPricingRate')
                ->has('currency'));

        // No params at all — defaults must keep working.
        $this->actingAs($admin)
            ->get(route('reports.guestMovements'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/GuestMovements'));
    }

    public function test_range_protection_is_central_not_local(): void
    {
        $admin = $this->admin();

        // A different range() caller with no validation of its own must now
        // reject garbage too — proof the fix lives in the shared helper.
        $this->actingAs($admin)
            ->getJson(route('reports.maintenanceSla', ['from' => 'abc']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);

        $this->actingAs($admin)
            ->getJson(route('reports.shifts', ['from' => '2026-01-01', 'to' => '2027-06-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }
}
