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

class ReservationChannelTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Room, 2: Guest} */
    private function setupHotel(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@test.local', 'phone' => '+355 69 000 0000']);

        return [$admin, $room, $guest];
    }

    public function test_admin_create_persists_selected_channel(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();

        $this->actingAs($admin)->post(route('reservations.store'), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed',
            'adults' => 2,
            'channel' => 'booking.com',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals('booking.com', Reservation::latest('id')->first()->channel);
    }

    public function test_admin_create_defaults_channel_to_manual(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();

        $this->actingAs($admin)->post(route('reservations.store'), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'adults' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals('manual', Reservation::latest('id')->first()->channel);
    }

    public function test_invalid_channel_is_rejected(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();

        $this->actingAs($admin)->post(route('reservations.store'), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'adults' => 1,
            'channel' => 'not-a-channel',
        ])->assertSessionHasErrors('channel');

        $this->assertSame(0, Reservation::count());
    }

    public function test_website_booking_is_tagged_direct(): void
    {
        [, $room, ] = $this->setupHotel();

        $this->post(route('website.book.submit'), [
            'room_id' => $room->id,
            'check_in' => now()->addDays(4)->toDateString(),
            'check_out' => now()->addDays(6)->toDateString(),
            'first_name' => 'Web',
            'last_name' => 'Guest',
            'email' => 'web@guest.local',
            'phone' => '+355 69 111 1111',
            'adults' => 2,
        ])->assertRedirect();

        $this->assertEquals('direct', Reservation::latest('id')->first()->channel);
    }

    public function test_index_exposes_channel_to_the_page(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();
        Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 160, 'adults' => 2, 'channel' => 'airbnb',
        ]);

        $this->actingAs($admin)->get(route('reservations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Index')
                ->where('reservations.data.0.channel', 'airbnb')
            );
    }
}
