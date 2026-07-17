<?php

namespace Tests\Feature;

use App\Mail\NewReservationMail;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeReservation(string $status = 'pending'): Reservation
    {
        $type = RoomType::create(['name' => 'Std', 'base_price' => 50, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest', 'email' => 'g@example.com', 'phone' => '+355600000000']);
        $creator = User::factory()->create();

        return Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $creator->id,
            'check_in_date' => '2026-07-01', 'check_out_date' => '2026-07-03',
            'status' => $status, 'total_amount' => 100, 'adults' => 2, 'children' => 0,
        ]);
    }

    public function test_new_reservation_emails_hotel_when_email_is_set(): void
    {
        Mail::fake();
        Setting::set('hotel.email', 'hotel@example.com');

        $this->makeReservation();

        Mail::assertSent(NewReservationMail::class);
    }

    public function test_no_email_when_hotel_email_is_missing(): void
    {
        Mail::fake();
        // hotel.email not set → observer must early-return.

        $this->makeReservation();

        Mail::assertNothingSent();
    }

    public function test_new_reservation_email_can_be_disabled_in_settings(): void
    {
        Mail::fake();
        Setting::set('hotel.email', 'hotel@example.com');
        Setting::set('notifications.email_new_reservations', false, 'boolean');

        $this->makeReservation();

        Mail::assertNothingSent();
    }

    public function test_booking_survives_a_mail_failure(): void
    {
        Setting::set('hotel.email', 'hotel@example.com');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $reservation = $this->makeReservation(); // must NOT throw

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
    }

    public function test_bell_endpoint_lists_pending_reservations(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->makeReservation('pending');

        $this->actingAs($admin)
            ->getJson(route('notifications.reservations'))
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_bell_endpoint_also_lists_confirmed_ota_style_reservations(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $reservation = $this->makeReservation('confirmed');

        $this->actingAs($admin)
            ->getJson(route('notifications.reservations'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('reservations.0.id', $reservation->id);
    }

    public function test_bell_endpoint_does_not_list_cancelled_reservations(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->makeReservation('cancelled');

        $this->actingAs($admin)
            ->getJson(route('notifications.reservations'))
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_bell_keeps_same_creator_ota_and_exposes_source_context(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $reservation = $this->makeReservation('confirmed');
        $reservation->forceFill([
            'created_by' => $admin->id,
            'channel' => 'booking.com',
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
        ])->save();

        $this->actingAs($admin)
            ->getJson(route('notifications.reservations'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('reservations.0.channel', 'booking.com')
            ->assertJsonPath('reservations.0.created_by', $admin->id)
            ->assertJsonPath('reservations.0.should_notify', true);
    }

    public function test_bell_marks_own_staff_direct_entry_for_suppression(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $reservation = $this->makeReservation('confirmed');
        $reservation->forceFill([
            'created_by' => $admin->id,
            'channel' => 'direct',
            'created_via' => Reservation::CREATED_VIA_STAFF,
        ])->save();

        $this->actingAs($admin)
            ->getJson(route('notifications.reservations'))
            ->assertOk()
            ->assertJsonPath('reservations.0.id', $reservation->id)
            ->assertJsonPath('reservations.0.should_notify', false);
    }

    public function test_bell_keeps_website_direct_entry_for_staff_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $system = User::factory()->create([
            'name' => 'Website Booking',
            'email' => 'system@villamucho.local',
        ]);

        $reservation = $this->makeReservation('confirmed');
        $reservation->forceFill([
            'created_by' => $system->id,
            'channel' => 'direct',
            'created_via' => Reservation::CREATED_VIA_WEBSITE,
        ])->save();

        $this->actingAs($admin)
            ->getJson(route('notifications.reservations'))
            ->assertOk()
            ->assertJsonPath('reservations.0.id', $reservation->id)
            ->assertJsonPath('reservations.0.should_notify', true);
    }

    public function test_bell_silences_bulk_import_entries(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $reservation = $this->makeReservation('confirmed');
        $reservation->forceFill([
            'channel' => 'booking.com',
            'created_via' => Reservation::CREATED_VIA_IMPORT,
        ])->save();

        $this->actingAs($admin)
            ->getJson(route('notifications.reservations'))
            ->assertOk()
            ->assertJsonPath('reservations.0.id', $reservation->id)
            ->assertJsonPath('reservations.0.should_notify', false);
    }
}
