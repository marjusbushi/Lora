<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OTA-sourced manual reservations must carry the OTA's reservation number:
 * without it, cancellations and modifications from the channel can't find the
 * reservation and the desk creates unlinked twins (the Novotny/Morvan cases).
 * Direct bookings keep the reference optional, and untouched legacy values
 * never block an unrelated edit.
 */
class ReservationChannelRefTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Room $room;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $this->room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $this->guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@test.local']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'guest_id' => $this->guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_id' => $this->room->id, 'adults' => 2, 'children' => 0]],
        ], $overrides);
    }

    public function test_ota_booking_without_a_reservation_number_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('reservations.store-multi'), $this->payload([
            'channel' => 'booking.com',
        ]))->assertSessionHasErrors('channel_ref');

        $this->assertSame(0, Reservation::count());
    }

    public function test_ota_booking_with_a_malformed_number_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('reservations.store-multi'), $this->payload([
            'channel' => 'booking.com',
            'channel_ref' => 'Booking #45218',
        ]))->assertSessionHasErrors('channel_ref');

        $this->assertSame(0, Reservation::count());
    }

    public function test_ota_booking_with_a_valid_number_is_created_and_linked(): void
    {
        $this->actingAs($this->admin)->post(route('reservations.store-multi'), $this->payload([
            'channel' => 'booking.com',
            'channel_ref' => '5438361798',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $reservation = Reservation::sole();
        $this->assertSame('booking.com', $reservation->channel);
        $this->assertSame('5438361798', $reservation->channel_ref);
    }

    public function test_reentering_an_existing_active_booking_number_is_blocked(): void
    {
        Reservation::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->admin->id,
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'check_in_date' => now()->addDays(10)->toDateString(),
            'check_out_date' => now()->addDays(12)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 200,
            'adults' => 2,
            'channel' => 'booking.com',
            'channel_ref' => '5438361798',
        ]);

        $this->actingAs($this->admin)->post(route('reservations.store-multi'), $this->payload([
            'channel' => 'booking.com',
            'channel_ref' => '5438361798',
        ]))->assertSessionHasErrors('channel_ref');

        $this->assertSame(1, Reservation::count());
    }

    public function test_a_cancelled_booking_number_may_be_reentered(): void
    {
        Reservation::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->admin->id,
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'check_in_date' => now()->addDays(10)->toDateString(),
            'check_out_date' => now()->addDays(12)->toDateString(),
            'status' => 'cancelled',
            'total_amount' => 200,
            'adults' => 2,
            'channel' => 'booking.com',
            'channel_ref' => '5438361798',
        ]);

        $this->actingAs($this->admin)->post(route('reservations.store-multi'), $this->payload([
            'channel' => 'booking.com',
            'channel_ref' => '5438361798',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Reservation::count());
    }

    public function test_direct_booking_needs_no_reference(): void
    {
        $this->actingAs($this->admin)->post(route('reservations.store-multi'), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Reservation::count());
    }

    public function test_editing_a_legacy_reservation_with_an_untouched_ref_still_works(): void
    {
        // Pre-rule manual OTA copy: junk ref that would fail today's format.
        $legacy = Reservation::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->admin->id,
            'created_via' => Reservation::CREATED_VIA_STAFF,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 200,
            'adults' => 2,
            'channel' => 'booking.com',
            'channel_ref' => 'Booking #45218',
        ]);

        $this->actingAs($this->admin)->put(route('reservations.update', $legacy), [
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'check_in_date' => now()->addDays(4)->toDateString(),
            'check_out_date' => now()->addDays(6)->toDateString(),
            'adults' => 2,
            'channel' => 'booking.com',
            'channel_ref' => 'Booking #45218',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(now()->addDays(4)->toDateString(), $legacy->fresh()->check_in_date->toDateString());
    }

    public function test_changing_the_ref_on_edit_is_validated(): void
    {
        $reservation = Reservation::create([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->admin->id,
            'created_via' => Reservation::CREATED_VIA_STAFF,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 200,
            'adults' => 2,
            'channel' => 'booking.com',
            'channel_ref' => '5438361798',
        ]);

        $this->actingAs($this->admin)->put(route('reservations.update', $reservation), [
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'adults' => 2,
            'channel' => 'booking.com',
            'channel_ref' => 'garbage!',
        ])->assertSessionHasErrors('channel_ref');

        $this->assertSame('5438361798', $reservation->fresh()->channel_ref);
    }
}
