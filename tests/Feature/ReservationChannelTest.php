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
            // OTA-sourced manual entries must carry the OTA's booking number.
            'channel_ref' => '5438361798',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reservation = Reservation::latest('id')->first();
        $this->assertEquals('booking.com', $reservation->channel);
        $this->assertEquals('5438361798', $reservation->channel_ref);
        $this->assertEquals(Reservation::CREATED_VIA_STAFF, $reservation->created_via);
    }

    public function test_admin_create_defaults_channel_to_direct(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();

        $this->actingAs($admin)->post(route('reservations.store'), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'adults' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reservation = Reservation::latest('id')->first();
        $this->assertEquals('direct', $reservation->channel);
        $this->assertEquals(Reservation::CREATED_VIA_STAFF, $reservation->created_via);
    }

    public function test_legacy_manual_payload_is_normalized_to_direct(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();

        $this->actingAs($admin)->post(route('reservations.store'), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'adults' => 1,
            'channel' => 'manual',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals('direct', Reservation::latest('id')->first()->channel);
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

    public function test_non_string_channel_is_rejected_without_server_error(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();

        $this->actingAs($admin)->post(route('reservations.store'), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'adults' => 1,
            'channel' => ['manual'],
        ])->assertSessionHasErrors('channel');

        $this->assertSame(0, Reservation::count());
    }

    public function test_website_booking_is_tagged_direct(): void
    {
        [, $room] = $this->setupHotel();

        $this->post(route('website.book.submit'), [
            'selections' => [['room_type_id' => $room->room_type_id, 'quantity' => 1]],
            'check_in' => now()->addDays(4)->toDateString(),
            'check_out' => now()->addDays(6)->toDateString(),
            'first_name' => 'Web',
            'last_name' => 'Guest',
            'email' => 'web@guest.local',
            'phone' => '+355 69 111 1111',
            'adults' => 2,
        ])->assertRedirect();

        $reservation = Reservation::latest('id')->first();
        $this->assertEquals('direct', $reservation->channel);
        $this->assertEquals(Reservation::CREATED_VIA_WEBSITE, $reservation->created_via);
    }

    public function test_show_exposes_channel_and_ref_to_the_detail_page(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();
        $res = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 160, 'adults' => 2,
            'channel' => 'booking.com', 'channel_ref' => '6006959033',
        ]);

        $this->actingAs($admin)->get(route('reservations.show', $res->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Show')
                ->where('reservation.channel', 'booking.com')
                ->where('reservation.channel_ref', '6006959033')
            );
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
                ->where('reservations.data.0.created_via', Reservation::CREATED_VIA_STAFF)
            );
    }

    public function test_update_rejects_non_string_channel_without_server_error(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 160,
            'adults' => 2,
        ]);

        $this->actingAs($admin)->put(route('reservations.update', $reservation), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => $reservation->check_in_date->toDateString(),
            'check_out_date' => $reservation->check_out_date->toDateString(),
            'status' => 'confirmed',
            'adults' => 2,
            'children' => 0,
            'channel' => ['manual'],
            'total_amount' => 160,
        ])->assertSessionHasErrors('channel');

        $this->assertSame('direct', $reservation->refresh()->channel);
    }

    public function test_synced_reservation_channel_cannot_be_changed_manually(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 160,
            'adults' => 2,
            'channel' => 'booking.com',
            'channel_ref' => 'OTA-123',
        ]);

        $this->actingAs($admin)->put(route('reservations.update', $reservation), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => $reservation->check_in_date->toDateString(),
            'check_out_date' => $reservation->check_out_date->toDateString(),
            'status' => 'confirmed',
            'adults' => 2,
            'children' => 0,
            'channel' => 'direct',
            'total_amount' => 160,
        ])->assertSessionHasErrors('channel');

        $this->assertSame('booking.com', $reservation->refresh()->channel);
    }
}
