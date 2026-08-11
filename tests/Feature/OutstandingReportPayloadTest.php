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
                // The migration-era duplicates are gone — analytics is the only source.
                ->missing('rows')
                ->missing('total'));
    }
}
