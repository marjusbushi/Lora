<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomMoveTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Room, 2: Room, 3: Guest} */
    private function setupHotel(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $room1 = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'occupied']);
        $room2 = Room::create(['room_type_id' => $type->id, 'room_number' => '102', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@test.local', 'phone' => '+355 69 000 0000']);

        return [$admin, $room1, $room2, $guest];
    }

    private function checkedInReservation(Room $room, Guest $guest, User $admin): Reservation
    {
        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => now()->subDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'status' => 'checked_in',
            'total_amount' => 300,
            'adults' => 2,
        ]);
    }

    public function test_move_room_relocates_a_checked_in_guest(): void
    {
        [$admin, $room1, $room2, $guest] = $this->setupHotel();
        $res = $this->checkedInReservation($room1, $guest, $admin);

        $this->actingAs($admin)->post(route('reservations.move-room', $res->id), [
            'room_id' => $room2->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $res->refresh();
        $this->assertEquals($room2->id, $res->room_id);
        // Stay data untouched.
        $this->assertEquals($guest->id, $res->guest_id);
        $this->assertEquals('checked_in', $res->status);
        $this->assertEqualsWithDelta(300.0, (float) $res->total_amount, 0.01);
        // Room statuses flipped + old room queued for cleaning.
        $this->assertEquals('occupied', $room2->refresh()->status);
        $this->assertEquals('cleaning', $room1->refresh()->status);
        $this->assertTrue(CleaningTask::where('room_id', $room1->id)->where('type', 'checkout_clean')->exists());
    }

    public function test_move_rejected_when_new_room_is_occupied_for_the_dates(): void
    {
        [$admin, $room1, $room2, $guest] = $this->setupHotel();
        $res = $this->checkedInReservation($room1, $guest, $admin);
        // room2 already booked over overlapping dates.
        Reservation::create([
            'room_id' => $room2->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 300,
            'adults' => 1,
        ]);

        $this->actingAs($admin)->post(route('reservations.move-room', $res->id), [
            'room_id' => $room2->id,
        ])->assertSessionHasErrors(['room_id']);

        $this->assertEquals($room1->id, $res->refresh()->room_id);
    }

    public function test_move_is_only_allowed_for_checked_in(): void
    {
        [$admin, $room1, $room2, $guest] = $this->setupHotel();
        $res = Reservation::create([
            'room_id' => $room1->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => now()->addDays(2)->toDateString(),
            'check_out_date' => now()->addDays(4)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 200,
            'adults' => 1,
        ]);

        $this->actingAs($admin)->post(route('reservations.move-room', $res->id), [
            'room_id' => $room2->id,
        ])->assertSessionHasErrors(['room_id']);

        $this->assertEquals($room1->id, $res->refresh()->room_id);
    }
}
