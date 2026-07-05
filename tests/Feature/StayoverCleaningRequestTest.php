<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StayoverCleaningRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Room, 2: Reservation} */
    private function scenario(string $resStatus = 'checked_in', string $roomStatus = 'occupied'): array
    {
        $this->seed(RolePermissionSeeder::class);

        $reception = User::factory()->create();
        $reception->assignRole('receptionist'); // has update_reservations, NOT any housekeeping perm

        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => $roomStatus]);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@test.local', 'phone' => '+355 69 000 0000']);
        $res = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $reception->id,
            'check_in_date' => now()->subDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'status' => $resStatus,
            'total_amount' => 300,
            'adults' => 2,
        ]);

        return [$reception, $room, $res];
    }

    public function test_reception_can_request_stayover_clean_for_in_house_guest(): void
    {
        [$reception, $room, $res] = $this->scenario();

        $this->actingAs($reception)
            ->post(route('reservations.request-cleaning', $res->id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cleaning_tasks', [
            'room_id' => $room->id,
            'type' => 'stayover_clean',
            'status' => 'pending',
        ]);
        // Room is untouched — the guest is still in-house.
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_request_rejected_when_not_checked_in(): void
    {
        [$reception, $room, $res] = $this->scenario('confirmed');

        $this->actingAs($reception)
            ->post(route('reservations.request-cleaning', $res->id))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('cleaning_tasks', ['room_id' => $room->id, 'type' => 'stayover_clean']);
    }

    public function test_no_duplicate_stayover_while_one_is_open(): void
    {
        [$reception, $room, $res] = $this->scenario();
        CleaningTask::create(['room_id' => $room->id, 'type' => 'stayover_clean', 'status' => 'pending']);

        $this->actingAs($reception)
            ->post(route('reservations.request-cleaning', $res->id))
            ->assertSessionHas('error');

        $this->assertSame(1, CleaningTask::where('room_id', $room->id)->where('type', 'stayover_clean')->count());
    }

    public function test_completing_a_stayover_leaves_the_occupied_room_occupied(): void
    {
        [$reception, $room, $res] = $this->scenario();
        // Empty stayover template so the task can be completed without ticking items.
        Setting::set('housekeeping.checklists', ['stayover_clean' => []], 'json');

        $this->actingAs($reception)->post(route('reservations.request-cleaning', $res->id));
        $task = CleaningTask::where('room_id', $room->id)->where('type', 'stayover_clean')->firstOrFail();

        $hk = User::factory()->create();
        $hk->assignRole('housekeeping');
        $this->actingAs($hk)->patch(route('housekeeping.status', $task->id), ['status' => 'in_progress']);
        $this->actingAs($hk)->patch(route('housekeeping.status', $task->id), ['status' => 'completed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $task->fresh()->status);
        // The guest never left → the room must stay occupied (NOT freed to available).
        $this->assertSame('occupied', $room->fresh()->status);
    }
}
