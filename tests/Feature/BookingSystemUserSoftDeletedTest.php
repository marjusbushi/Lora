<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: the public booking funnel 500'd for 11 days because the
 * `system@villamucho.local` user (which every public booking is attributed to)
 * had been SOFT-DELETED from the admin. `User::firstOrCreate` couldn't see the
 * trashed row (SoftDeletes scope) but the users.email UNIQUE index still counted
 * it, so the re-INSERT died with a duplicate-key 1062 on every submission.
 *
 * The fix looks up including trashed rows and restores the user, so the funnel
 * self-heals instead of going down.
 */
class BookingSystemUserSoftDeletedTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_booking_succeeds_when_system_user_is_soft_deleted(): void
    {
        // A previous booking self-seeded the system user; a staff member then
        // soft-deleted it from the admin Users screen (the real prod incident).
        $system = User::factory()->create([
            'email' => 'system@villamucho.local',
            'name' => 'Website Booking',
        ]);
        $system->delete();
        $this->assertSoftDeleted('users', ['id' => $system->id]);

        $type = RoomType::create(['name' => 'Double', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => 'A', 'floor' => 1, 'status' => 'available']);

        $response = $this->post(route('website.book.submit'), [
            'room_id' => $room->id,
            'check_in' => today()->addDays(3)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
            'first_name' => 'Ana',
            'last_name' => 'Testi',
            'email' => 'ana@example.com',
            'phone' => '0691234567',
            'adults' => 2,
            'children' => 0,
        ]);

        // Before the fix this was a 500 (duplicate-key). Now it redirects to confirmation.
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // The reservation was created and attributed to the SAME system user (restored, not a new row).
        $this->assertDatabaseHas('reservations', [
            'room_id' => $room->id,
            'created_by' => $system->id,
            'channel' => 'direct',
        ]);

        // The system user self-healed: un-trashed, and still exactly one such row (no duplicate insert).
        $this->assertDatabaseHas('users', ['id' => $system->id, 'deleted_at' => null]);
        $this->assertSame(1, User::withTrashed()->where('email', 'system@villamucho.local')->count());
    }

    public function test_public_booking_still_works_when_system_user_is_active(): void
    {
        $type = RoomType::create(['name' => 'Double', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => 'A', 'floor' => 1, 'status' => 'available']);

        // No system user seeded yet — the funnel must self-seed it (original task-65 behaviour).
        $response = $this->post(route('website.book.submit'), [
            'room_id' => $room->id,
            'check_in' => today()->addDays(3)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
            'first_name' => 'Beni',
            'last_name' => 'Testi',
            'email' => 'beni@example.com',
            'phone' => '0691234568',
            'adults' => 1,
            'children' => 0,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'system@villamucho.local', 'deleted_at' => null]);
        $this->assertDatabaseHas('reservations', ['room_id' => $room->id, 'channel' => 'direct']);
    }
}
