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

class ReservationConflictResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Room, Room, Guest, Reservation, Reservation} */
    private function conflictScenario(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Junior Suite', 'base_price' => 120, 'max_occupancy' => 3, 'amenities' => []]);
        $room201 = Room::create(['room_type_id' => $type->id, 'room_number' => '201', 'floor' => 2, 'status' => 'available']);
        $room202 = Room::create(['room_type_id' => $type->id, 'room_number' => '202', 'floor' => 2, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Sara', 'last_name' => 'Test', 'email' => 'sara@test.local', 'phone' => '+355 69 000 0000']);

        $first = Reservation::create([
            'room_id' => $room201->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => '2026-07-20',
            'check_out_date' => '2026-07-25',
            'status' => 'confirmed',
            'total_amount' => 600,
            'adults' => 2,
        ]);
        $second = Reservation::create([
            'room_id' => $room201->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => '2026-07-22',
            'check_out_date' => '2026-07-27',
            'status' => 'confirmed',
            'total_amount' => 600,
            'adults' => 2,
        ]);

        return [$admin, $room201, $room202, $guest, $first, $second];
    }

    public function test_calendar_exposes_real_conflict_and_same_type_suggestion(): void
    {
        [$admin, $room201, $room202, , , $second] = $this->conflictScenario();

        $this->actingAs($admin)->get(route('reservations.calendar', [
            'start' => '2026-07-20',
            'days' => 14,
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conflicts', 1)
            ->where('conflicts.0.room_id', $room201->id)
            ->where('conflicts.0.start_date', '2026-07-22')
            ->where('conflicts.0.end_date', '2026-07-25')
            ->where('conflicts.0.reservations.1.id', $second->id)
            ->where('conflicts.0.reservations.1.suggested_rooms.0.id', $room202->id)
            ->where('conflicts.0.reservations.1.suggested_rooms.0.same_type', true));
    }

    public function test_confirmed_conflict_can_be_resolved_into_an_available_room(): void
    {
        [$admin, , $room202, , , $second] = $this->conflictScenario();

        $this->actingAs($admin)->post(route('reservations.resolve-conflict', $second), [
            'room_id' => $room202->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($room202->id, $second->refresh()->room_id);
    }

    public function test_multiple_overlaps_in_one_room_are_grouped_as_one_case(): void
    {
        [$admin, $room201, , $guest] = $this->conflictScenario();
        Reservation::create([
            'room_id' => $room201->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => '2026-07-24',
            'check_out_date' => '2026-07-29',
            'status' => 'confirmed',
            'total_amount' => 600,
            'adults' => 2,
        ]);

        $this->actingAs($admin)->get(route('reservations.calendar', [
            'start' => '2026-07-20',
            'days' => 14,
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conflicts', 1)
            ->where('conflicts.0.start_date', '2026-07-22')
            ->where('conflicts.0.end_date', '2026-07-27')
            ->has('conflicts.0.reservations', 3));
    }

    public function test_conflict_resolution_rechecks_target_room_availability(): void
    {
        [$admin, $room201, $room202, $guest, , $second] = $this->conflictScenario();
        Reservation::create([
            'room_id' => $room202->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => '2026-07-21',
            'check_out_date' => '2026-07-26',
            'status' => 'confirmed',
            'total_amount' => 500,
            'adults' => 1,
        ]);

        $this->actingAs($admin)->post(route('reservations.resolve-conflict', $second), [
            'room_id' => $room202->id,
        ])->assertSessionHasErrors(['room_id']);

        $this->assertSame($room201->id, $second->refresh()->room_id);
    }
}
