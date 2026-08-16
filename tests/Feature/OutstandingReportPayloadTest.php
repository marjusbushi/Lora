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

class OutstandingReportPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_carries_currency_keys_and_no_legacy_duplicates(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'B']);
        Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->subDays(3)->toDateString(),
            'check_out_date' => today()->subDay()->toDateString(),
            'status' => 'checked_out', 'total_amount' => 150, 'adults' => 2, 'channel' => 'direct',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.outstanding'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Outstanding')
                ->has('analytics.rows', 1)
                ->where('analytics.summary.count', 1)
                ->where('analytics.rows.0.balance', fn ($balance) => abs((float) $balance - 150.0) < 0.005)
                ->has('pricingCurrency')
                ->has('baseToPricingRate')
                // v2 contract is additive: states + real/exposure split + filters echo.
                ->where('analytics.rows.0.state', 'due')
                ->has('analytics.summary.real_total')
                ->has('analytics.summary.real_count')
                ->has('analytics.summary.exposure_total')
                ->has('analytics.summary.exposure_count')
                ->has('analytics.buckets')
                ->has('analytics.statuses')
                ->where('filters.as_of', null)
                // The migration-era duplicates are gone — analytics is the only source.
                ->missing('rows')
                ->missing('total'));
    }

    public function test_as_of_and_arrival_params_are_accepted_and_echoed(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $yesterday = today()->subDay()->toDateString();

        $this->actingAs($admin)
            ->get(route('reports.outstanding', ['as_of' => $yesterday, 'arrival_from' => $yesterday]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Outstanding')
                ->where('filters.as_of', $yesterday)
                ->where('filters.arrival_from', $yesterday)
                ->where('analytics.as_of', $yesterday));
    }

    public function test_invalid_filter_params_are_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('reports.outstanding', ['as_of' => today()->addDay()->toDateString()]))
            ->assertSessionHasErrors(['as_of']);

        $this->actingAs($admin)
            ->getJson(route('reports.outstanding', ['as_of' => today()->addDay()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['as_of']);

        $this->actingAs($admin)
            ->getJson(route('reports.outstanding', ['as_of' => 'jo-datë']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['as_of']);

        $this->actingAs($admin)
            ->getJson(route('reports.outstanding', ['arrival_from' => '2026-08-10', 'arrival_to' => '2026-08-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['arrival_to']);
    }
}
