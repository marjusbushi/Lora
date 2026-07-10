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

class ReservationFolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_folio_enforces_hotel_charge_rules(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create([
            'name' => 'Standard',
            'base_price' => 80,
            'max_occupancy' => 2,
            'amenities' => [],
        ]);
        $room = Room::create([
            'room_type_id' => $type->id,
            'room_number' => '701',
            'floor' => 7,
            'status' => 'available',
        ]);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => '2026-09-10',
            'check_out_date' => '2026-09-12',
            'status' => 'confirmed',
            'total_amount' => 160,
            'adults' => 2,
        ]);

        $this->actingAs($admin)->post(route('reservations.folio.add', $reservation), [
            'type' => 'extra',
            'description' => 'Lavanderi',
            'amount' => 12,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('reservations.folio.add', $reservation), [
            'type' => 'bar',
            'description' => 'Pije e futur me dore',
            'amount' => 5,
        ])->assertSessionHasErrors('type');

        $this->actingAs($admin)->post(route('reservations.folio.add', $reservation), [
            'type' => 'discount',
            'description' => 'Zbritje mbi totalin',
            'amount' => 1000,
        ])->assertSessionHasErrors('amount');

        $reservation->update(['status' => 'checked_out']);
        $this->actingAs($admin)->post(route('reservations.folio.add', $reservation), [
            'type' => 'extra',
            'description' => 'Tarife pas mbylljes',
            'amount' => 10,
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseCount('folio_items', 1);
        $this->assertDatabaseHas('folio_items', [
            'reservation_id' => $reservation->id,
            'type' => 'extra',
            'description' => 'Lavanderi',
        ]);
    }
}
