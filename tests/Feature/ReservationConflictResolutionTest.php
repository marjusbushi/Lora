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

    public function test_room_suggestions_include_children_in_the_capacity_check(): void
    {
        [$admin, $room201, $room202, , , $second] = $this->conflictScenario();
        $second->update(['adults' => 2, 'children' => 1]);
        $smallType = RoomType::create(['name' => 'Double', 'base_price' => 90, 'max_occupancy' => 2, 'amenities' => []]);
        Room::create(['room_type_id' => $smallType->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);

        $this->actingAs($admin)->get(route('reservations.calendar', [
            'start' => '2026-07-20',
            'days' => 14,
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conflicts', 1)
            ->where('conflicts.0.room_id', $room201->id)
            ->has('conflicts.0.reservations.1.suggested_rooms', 1)
            ->where('conflicts.0.reservations.1.suggested_rooms.0.id', $room202->id));
    }

    public function test_conflict_resolution_includes_children_in_the_capacity_check(): void
    {
        [$admin, $room201, , , , $second] = $this->conflictScenario();
        $second->update(['adults' => 2, 'children' => 1]);
        $smallType = RoomType::create(['name' => 'Double', 'base_price' => 90, 'max_occupancy' => 2, 'amenities' => []]);
        $smallRoom = Room::create(['room_type_id' => $smallType->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);

        $this->actingAs($admin)->post(route('reservations.resolve-conflict', $second), [
            'room_id' => $smallRoom->id,
        ])->assertSessionHasErrors(['room_id']);

        $this->assertSame($room201->id, $second->refresh()->room_id);
    }

    /**
     * FUTURE-dated conflict scenario (the reshuffle engine refuses spans that
     * already started): first (the keeper) and second collide on room 201.
     *
     * @return array{User, Room, Room, Guest, Reservation, Reservation}
     */
    private function futureConflictScenario(int $secondOutDays = 6): array
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Junior Suite', 'base_price' => 120, 'max_occupancy' => 3, 'amenities' => []]);
        $room201 = Room::create(['room_type_id' => $type->id, 'room_number' => '201', 'floor' => 2, 'status' => 'available']);
        $room202 = Room::create(['room_type_id' => $type->id, 'room_number' => '202', 'floor' => 2, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Sara', 'last_name' => 'Test', 'email' => 'sara@test.local', 'phone' => '+355 69 000 0000']);

        $stay = fn (Room $room, int $in, int $out, array $attrs = []) => Reservation::create(array_merge([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => today()->addDays($in)->toDateString(),
            'check_out_date' => today()->addDays($out)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 600,
            'adults' => 2,
        ], $attrs));

        $first = $stay($room201, 1, 4);
        $second = $stay($room201, 1, $secondOutDays);

        return [$admin, $room201, $room202, $guest, $first, $second];
    }

    public function test_cross_type_rooms_are_never_primary_suggestions(): void
    {
        // The "205 incident" regression: every same-type room blocked for good
        // (checked-in guest — no chain can exist), a big room of ANOTHER type
        // wide open. It must appear only as the explicit cross-type valve.
        [$admin, $room201, $room202, $guest, , $second] = $this->futureConflictScenario();
        Reservation::create([
            'room_id' => $room202->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(), 'check_out_date' => today()->addDays(9)->toDateString(),
            'status' => 'checked_in', 'total_amount' => 900, 'adults' => 1,
        ]);
        $apartmentType = RoomType::create(['name' => 'Apartment', 'base_price' => 200, 'max_occupancy' => 5, 'amenities' => []]);
        $apartment = Room::create(['room_type_id' => $apartmentType->id, 'room_number' => '301', 'floor' => 3, 'status' => 'available']);

        $this->actingAs($admin)->get(route('reservations.calendar', [
            'start' => today()->toDateString(),
            'days' => 14,
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conflicts', 1)
            ->has('conflicts.0.reservations.1.suggested_rooms', 0) // NEVER the apartment
            ->where('conflicts.0.reservations.1.reshuffle_plan', null)
            ->has('conflicts.0.reservations.1.cross_type_rooms', 1)
            ->where('conflicts.0.reservations.1.cross_type_rooms.0.id', $apartment->id)
            ->where('conflicts.0.reservations.1.cross_type_rooms.0.same_type', false));
    }

    public function test_fragmentation_yields_a_chain_plan_that_moves_the_other_party(): void
    {
        // The #7421 room-305 shape: the conflicted stay itself fits nowhere,
        // but moving the KEEPER into 202's gap frees 201 entirely. The old
        // solver declared "no alternative" here.
        [$admin, $room201, $room202, $guest, $first, $second] = $this->futureConflictScenario();
        Reservation::create([ // blocks 202 for second's full span, but not for first's
            'room_id' => $room202->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->addDays(5)->toDateString(), 'check_out_date' => today()->addDays(9)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 400, 'adults' => 2,
        ]);

        $this->actingAs($admin)->get(route('reservations.calendar', [
            'start' => today()->toDateString(),
            'days' => 14,
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conflicts', 1)
            ->has('conflicts.0.reservations.1.suggested_rooms', 0)
            ->where('conflicts.0.reservations.1.reshuffle_plan.room.room_number', '201')
            ->has('conflicts.0.reservations.1.reshuffle_plan.moves', 1)
            ->where('conflicts.0.reservations.1.reshuffle_plan.moves.0.reservation_id', $first->id)
            ->where('conflicts.0.reservations.1.reshuffle_plan.moves.0.from_room_number', '201')
            ->where('conflicts.0.reservations.1.reshuffle_plan.moves.0.to_room_number', '202')
            ->where('conflicts.0.reservations.1.reshuffle_plan.moves.0.guest_name', 'Sara Test'));
    }

    public function test_reshuffle_mode_applies_the_chain_and_clears_the_conflict(): void
    {
        [$admin, $room201, $room202, $guest, $first, $second] = $this->futureConflictScenario();
        Reservation::create([
            'room_id' => $room202->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->addDays(5)->toDateString(), 'check_out_date' => today()->addDays(9)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 400, 'adults' => 2,
        ]);

        $this->actingAs($admin)->post(route('reservations.resolve-conflict', $second), [
            'mode' => 'reshuffle',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($room202->id, $first->refresh()->room_id); // the keeper moved into the gap
        $this->assertStringContainsString('për të zgjidhur një konflikt', $first->notes);
        $this->assertSame($room201->id, $second->refresh()->room_id); // conflicted stay owns 201 now
        $this->assertFalse(app(\App\Services\ReservationConflictService::class)->hasConflict($second->refresh()));
        // The moves never rewrite what the guests booked.
        $this->assertSame($room201->room_type_id, $first->booked_room_type_id);
    }

    public function test_reshuffle_mode_rejects_when_the_conflict_no_longer_exists(): void
    {
        [$admin, , $room202, , , $second] = $this->futureConflictScenario();
        $second->update(['room_id' => $room202->id]); // conflict already resolved by hand

        $this->actingAs($admin)->post(route('reservations.resolve-conflict', $second), [
            'mode' => 'reshuffle',
        ])->assertSessionHasErrors(['mode']);

        $this->assertSame($room202->id, $second->refresh()->room_id);
    }

    public function test_reshuffle_mode_rejects_when_no_legal_plan_exists(): void
    {
        // 202 is pinned by a checked-in guest for the whole span — no chain can
        // free a Junior Suite room, and nothing may be half-moved.
        [$admin, $room201, $room202, $guest, $first, $second] = $this->futureConflictScenario();
        $blocker = Reservation::create([
            'room_id' => $room202->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(), 'check_out_date' => today()->addDays(9)->toDateString(),
            'status' => 'checked_in', 'total_amount' => 900, 'adults' => 1,
        ]);

        $this->actingAs($admin)->post(route('reservations.resolve-conflict', $second), [
            'mode' => 'reshuffle',
        ])->assertSessionHasErrors(['mode']);

        $this->assertSame($room201->id, $first->refresh()->room_id);
        $this->assertSame($room201->id, $second->refresh()->room_id);
        $this->assertSame($room202->id, $blocker->refresh()->room_id);
    }

    public function test_cross_type_direct_resolution_keeps_the_booked_type(): void
    {
        // The escape valve changes the ROOM, never the record of what was booked.
        [$admin, $room201, $room202, $guest, , $second] = $this->futureConflictScenario();
        Reservation::create([
            'room_id' => $room202->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->toDateString(), 'check_out_date' => today()->addDays(9)->toDateString(),
            'status' => 'checked_in', 'total_amount' => 900, 'adults' => 1,
        ]);
        $apartmentType = RoomType::create(['name' => 'Apartment', 'base_price' => 200, 'max_occupancy' => 5, 'amenities' => []]);
        $apartment = Room::create(['room_type_id' => $apartmentType->id, 'room_number' => '301', 'floor' => 3, 'status' => 'available']);

        $this->actingAs($admin)->post(route('reservations.resolve-conflict', $second), [
            'room_id' => $apartment->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $second->refresh();
        $this->assertSame($apartment->id, $second->room_id);
        $this->assertSame($room201->room_type_id, $second->booked_room_type_id); // still the Junior Suite product
    }
}
