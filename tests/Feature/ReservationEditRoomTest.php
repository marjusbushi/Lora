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

class ReservationEditRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_can_change_the_room(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $room1 = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $room2 = Room::create(['room_type_id' => $type->id, 'room_number' => '102', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test']);

        $res = Reservation::create([
            'room_id' => $room1->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(6)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 300,
            'adults' => 2,
        ]);

        // Change ONLY the room to a free room — send exactly what the edit modal sends.
        $response = $this->actingAs($admin)->put(route('reservations.update', $res->id), [
            'room_id' => $room2->id,
            'guest_id' => $guest->id,
            'check_in_date' => $res->check_in_date->toDateString(),
            'check_out_date' => $res->check_out_date->toDateString(),
            'status' => 'confirmed',
            'adults' => 2,
            'children' => 0,
            'notes' => '',
            'channel' => 'manual',
            'total_amount' => 300,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertEquals($room2->id, $res->refresh()->room_id, 'Room change did not persist');
    }

    public function test_editing_into_a_maintenance_room_is_rejected_with_clear_message(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $room1 = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $maint = Room::create(['room_type_id' => $type->id, 'room_number' => '205', 'floor' => 2, 'status' => 'maintenance']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test']);

        $res = Reservation::create([
            'room_id' => $room1->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(6)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 300,
            'adults' => 2,
        ]);

        $response = $this->actingAs($admin)->put(route('reservations.update', $res->id), [
            'room_id' => $maint->id,
            'guest_id' => $guest->id,
            'check_in_date' => $res->check_in_date->toDateString(),
            'check_out_date' => $res->check_out_date->toDateString(),
            'status' => 'confirmed',
            'adults' => 2,
            'children' => 0,
            'notes' => '',
            'channel' => 'manual',
            'total_amount' => 300,
        ]);

        $response->assertSessionHasErrors(['room_id']);
        $this->assertStringContainsString('mirembajtje', session('errors')->first('room_id'));
        $this->assertEquals($room1->id, $res->refresh()->room_id);
    }
}
