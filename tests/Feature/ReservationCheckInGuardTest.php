<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Check-in blocks only for REAL reasons, each with a message naming it:
 * another guest still in-house, an open cleaning task, or maintenance. A
 * stale room status alone (occupied/cleaning with nothing behind it) heals
 * itself — four same-day Saturn repairs (rooms 006, 106, 003, 104) came from
 * the old guard refusing a room whose only "occupant" was the very stay
 * being checked in.
 */
class ReservationCheckInGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-21 12:00:00');
        \Carbon\Carbon::setTestNow('2026-08-21 12:00:00');
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function room(string $number, string $status = 'available'): Room
    {
        $type = RoomType::firstOrCreate(
            ['name' => 'Std'],
            ['base_price' => 80, 'max_occupancy' => 3, 'amenities' => []],
        );

        return Room::create(['room_type_id' => $type->id, 'room_number' => $number, 'floor' => 1, 'status' => $status]);
    }

    private function reservation(
        Room $room,
        string $status = 'confirmed',
        string $in = '2026-08-20',
        string $out = '2026-08-21',
        string $firstName = 'Guard',
        string $lastName = 'Guest',
    ): Reservation {
        $guest = Guest::create(['first_name' => $firstName, 'last_name' => $lastName.' '.$room->room_number.$in]);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $this->admin->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'status' => $status,
            'total_amount' => 100,
            'adults' => 1,
            'channel' => 'direct',
        ]);
    }

    public function test_stale_occupied_room_no_longer_blocks_its_own_check_in(): void
    {
        // Mirror of Saturn rooms 006/106: room flipped occupied by hand, the
        // stay itself never checked in, nobody else in-house.
        $room = $this->room('101', 'occupied');
        $reservation = $this->reservation($room);

        $this->actingAs($this->admin)
            ->post(route('reservations.check-in', $reservation))
            ->assertSessionHasNoErrors();

        $this->assertSame('checked_in', $reservation->fresh()->status);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_stale_cleaning_status_without_a_task_no_longer_blocks(): void
    {
        $room = $this->room('102', 'cleaning');
        $reservation = $this->reservation($room);

        $this->actingAs($this->admin)
            ->post(route('reservations.check-in', $reservation))
            ->assertSessionHasNoErrors();

        $this->assertSame('checked_in', $reservation->fresh()->status);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_another_in_house_guest_blocks_and_the_message_names_them(): void
    {
        // Mirror of Saturn 006/SAUD: the previous guest's checkout was missed.
        $room = $this->room('103', 'occupied');
        $overstay = $this->reservation($room, 'checked_in', '2026-08-16', '2026-08-17', 'Saud', 'Alkholifi');
        $arriving = $this->reservation($room, 'confirmed', '2026-08-19', '2026-08-22', 'Samir', 'Arslanoski');

        $this->actingAs($this->admin)->post(route('reservations.check-in', $arriving))
            ->assertInvalid(['check_in' => 'Saud'])
            ->assertInvalid(['check_in' => "#{$overstay->id}"])
            ->assertInvalid(['check_in' => 'check-out']);

        $this->assertSame('confirmed', $arriving->fresh()->status);
        $this->assertSame('checked_in', $overstay->fresh()->status);
    }

    public function test_open_cleaning_task_blocks_with_a_housekeeping_message(): void
    {
        $room = $this->room('104');
        $reservation = $this->reservation($room);
        CleaningTask::create(['room_id' => $room->id, 'type' => 'checkout_clean', 'status' => 'pending', 'priority' => 'normal']);

        $this->actingAs($this->admin)->post(route('reservations.check-in', $reservation))
            ->assertInvalid(['check_in' => 'pastrim te hapur'])
            ->assertInvalid(['check_in' => 'Housekeeping']);

        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    public function test_maintenance_room_blocks_with_a_maintenance_message(): void
    {
        $room = $this->room('105', 'maintenance');
        $reservation = $this->reservation($room);

        $this->actingAs($this->admin)->post(route('reservations.check-in', $reservation))
            ->assertInvalid(['check_in' => 'mirembajtje']);

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertSame('maintenance', $room->fresh()->status);
    }

    public function test_non_confirmed_reservations_are_still_refused(): void
    {
        $pending = $this->reservation($this->room('106'), 'pending');

        $this->actingAs($this->admin)->post(route('reservations.check-in', $pending))
            ->assertSessionHasErrors(['check_in']);

        $this->assertSame('pending', $pending->fresh()->status);
    }
}
