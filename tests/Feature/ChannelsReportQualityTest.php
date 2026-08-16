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

class ChannelsReportQualityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeStay(User $admin, RoomType $type, string $room, string $channel, float $commission, ?string $ref = null): Reservation
    {
        return Reservation::create([
            'room_id' => Room::create(['room_type_id' => $type->id, 'room_number' => $room, 'floor' => 1, 'status' => 'occupied'])->id,
            'guest_id' => Guest::create(['first_name' => 'Ana', 'last_name' => $room])->id,
            'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(),
            'check_out_date' => today()->addDay()->toDateString(),
            'status' => 'checked_in', 'total_amount' => 100, 'adults' => 2,
            'channel' => $channel, 'channel_ref' => $ref, 'commission_amount' => $commission,
        ]);
    }

    public function test_flags_ota_reservations_missing_commission_and_carries_currency_keys(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);

        $this->makeStay($admin, $type, '101', 'booking.com', 0, '111');   // the gap — must be flagged
        $this->makeStay($admin, $type, '102', 'booking.com', 18, '222');  // healthy OTA booking
        $this->makeStay($admin, $type, '103', 'direct', 0);               // direct never owes commission
        // Multi-room booking: whole commission on the FIRST room row (importer
        // semantics) — the zero-commission sibling must NOT count as missing.
        $this->makeStay($admin, $type, '104', 'booking.com', 40, '333');
        $this->makeStay($admin, $type, '105', 'booking.com', 0, '333');

        $this->actingAs($admin)
            ->get(route('reports.channels'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Channels')
                ->where('analytics.current.data_quality.ota_missing_commission', 1)
                ->has('pricingCurrency')
                ->has('baseToPricingRate'));
    }

    public function test_stays_silent_when_every_ota_reservation_has_commission(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);

        $this->makeStay($admin, $type, '201', 'booking.com', 18, '444');
        $this->makeStay($admin, $type, '202', 'direct', 0);

        $this->actingAs($admin)
            ->get(route('reports.channels'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Channels')
                ->where('analytics.current.data_quality.ota_missing_commission', 0));
    }
}
