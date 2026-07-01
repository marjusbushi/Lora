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

class ReservationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_reservation_shows_on_calendar_and_list(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '301', 'floor' => 3, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Besim', 'last_name' => 'Wp', 'phone' => '+38349966102']);

        // Dates inside the calendar's default 14-day window (startOfWeek .. +13).
        $checkIn = now()->addDay()->toDateString();
        $checkOut = now()->addDays(4)->toDateString();

        $this->actingAs($admin)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'channel' => 'direct',
            'rooms' => [
                ['room_id' => $room->id, 'adults' => 2, 'children' => 0, 'total_amount' => 300],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // It must actually persist.
        $this->assertCount(1, Reservation::all());
        $this->assertEquals($room->id, Reservation::first()->room_id);

        // It must appear on the calendar (within the default window).
        $this->actingAs($admin)->get(route('reservations.calendar'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Reservations/Calendar')
                ->has('reservations', 1));

        // And on the list.
        $this->actingAs($admin)->get(route('reservations.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Reservations/Index')
                ->has('reservations.data', 1));
    }

    public function test_list_exposes_dates_as_plain_ymd_so_edit_does_not_shift(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '501', 'floor' => 5, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Erjon', 'last_name' => 'Lushnja']);

        Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => '2026-09-07',
            'check_out_date' => '2026-09-14',
            'status' => 'confirmed',
            'total_amount' => 700,
            'adults' => 2,
        ]);

        // The list must expose plain 'Y-m-d' (not a UTC ISO datetime), otherwise
        // openEdit's .split('T')[0] reads the day before.
        $this->actingAs($admin)->get(route('reservations.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('reservations.data.0', fn (AssertableInertia $r) => $r
                    ->where('check_in_date', '2026-09-07')
                    ->where('check_out_date', '2026-09-14')
                    ->etc()));
    }
}
