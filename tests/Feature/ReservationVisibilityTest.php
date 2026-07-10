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

    public function test_list_puts_the_most_recently_received_reservation_first(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '601', 'floor' => 6, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Newest']);

        $older = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => '2027-12-20',
            'check_out_date' => '2027-12-22',
            'status' => 'confirmed',
            'total_amount' => 200,
            'adults' => 2,
        ]);
        $older->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        $newer = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => '2026-08-10',
            'check_out_date' => '2026-08-12',
            'status' => 'confirmed',
            'total_amount' => 200,
            'adults' => 2,
        ]);

        $this->actingAs($admin)->get(route('reservations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('latestReservationId', $newer->id)
                ->where('reservations.data.0.id', $newer->id)
                ->where('reservations.data.1.id', $older->id));
    }

    public function test_list_supports_only_the_allowed_page_sizes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        foreach ([25, 50, 100] as $perPage) {
            $this->actingAs($admin)->get(route('reservations.index', ['per_page' => $perPage]))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('reservations.per_page', $perPage)
                    ->where('filters.per_page', $perPage)
                    ->where('filters.sort', 'latest'));
        }

        $this->actingAs($admin)->get(route('reservations.index', ['per_page' => 999]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reservations.per_page', 25)
                ->where('filters.per_page', 25));
    }

    public function test_list_supports_operational_checkin_and_checkout_sorting(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '701', 'floor' => 7, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Sort', 'last_name' => 'Test']);

        $makeReservation = fn (string $status, int $checkInOffset, int $checkOutOffset) => Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => today()->addDays($checkInOffset)->toDateString(),
            'check_out_date' => today()->addDays($checkOutOffset)->toDateString(),
            'status' => $status,
            'total_amount' => 200,
            'adults' => 2,
        ]);

        $futureArrival = $makeReservation('confirmed', 3, 5);
        $todayArrival = $makeReservation('confirmed', 0, 2);
        $laterCheckout = $makeReservation('checked_in', -2, 3);
        $todayCheckout = $makeReservation('checked_in', -3, 0);
        $cancelled = $makeReservation('cancelled', -10, -8);

        $this->actingAs($admin)->get(route('reservations.index', ['sort' => 'checkin']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.sort', 'checkin')
                ->where('reservations.data.0.id', $todayArrival->id)
                ->where('reservations.data.1.id', $futureArrival->id)
                ->where('reservations.data.4.id', $cancelled->id));

        $this->actingAs($admin)->get(route('reservations.index', ['sort' => 'checkout']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.sort', 'checkout')
                ->where('reservations.data.0.id', $todayCheckout->id)
                ->where('reservations.data.1.id', $laterCheckout->id)
                ->where('reservations.data.4.id', $cancelled->id));
    }

    public function test_list_rejects_unknown_sort_values(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        foreach (['unknown', ['latest']] as $sort) {
            $this->actingAs($admin)->get(route('reservations.index', ['sort' => $sort]))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('filters.sort', 'latest'));
        }
    }
}
