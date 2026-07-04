<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiRoomReservationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Room, 2: Room, 3: Guest} */
    private function setupHotel(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $room1 = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $room2 = Room::create(['room_type_id' => $type->id, 'room_number' => '102', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@test.local', 'phone' => '+355 69 000 0000']);

        return [$admin, $room1, $room2, $guest];
    }

    public function test_multi_room_creates_linked_reservations_for_one_guest(): void
    {
        [$admin, $room1, $room2, $guest] = $this->setupHotel();
        $guestCountBefore = Guest::count();

        $this->actingAs($admin)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'channel' => 'manual',
            'rooms' => [
                ['room_id' => $room1->id, 'adults' => 2, 'children' => 0, 'total_amount' => 200],
                ['room_id' => $room2->id, 'adults' => 1, 'children' => 1, 'total_amount' => null],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reservations = Reservation::all();
        $this->assertCount(2, $reservations);
        // Same guest, NOT duplicated.
        $this->assertEquals([$guest->id, $guest->id], $reservations->pluck('guest_id')->all());
        $this->assertEquals($guestCountBefore, Guest::count());
        // Linked by one common, non-null booking_group_id.
        $groups = $reservations->pluck('booking_group_id')->unique();
        $this->assertCount(1, $groups);
        $this->assertNotNull($groups->first());
        // Second room's price defaulted to base_price (100) × 2 nights = 200.
        $this->assertEqualsWithDelta(200.0, (float) $reservations->firstWhere('room_id', $room2->id)->total_amount, 0.01);
    }

    public function test_single_room_has_no_group_id(): void
    {
        [$admin, $room1, , $guest] = $this->setupHotel();

        $this->actingAs($admin)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [
                ['room_id' => $room1->id, 'adults' => 2, 'children' => 0],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(1, Reservation::all());
        $this->assertNull(Reservation::first()->booking_group_id);
    }

    public function test_over_capacity_rolls_back_the_whole_booking(): void
    {
        [$admin, $room1, $room2, $guest] = $this->setupHotel();

        $this->actingAs($admin)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [
                ['room_id' => $room1->id, 'adults' => 2, 'children' => 0],   // ok
                ['room_id' => $room2->id, 'adults' => 3, 'children' => 2],   // 5 > max_occupancy 3
            ],
        ])->assertSessionHasErrors(['rooms']);

        // All-or-nothing: the valid room must NOT have been created either.
        $this->assertCount(0, Reservation::all());
    }

    public function test_duplicate_room_is_rejected(): void
    {
        [$admin, $room1, , $guest] = $this->setupHotel();

        $this->actingAs($admin)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [
                ['room_id' => $room1->id, 'adults' => 1],
                ['room_id' => $room1->id, 'adults' => 1],
            ],
        ])->assertSessionHasErrors(['rooms']);

        $this->assertCount(0, Reservation::all());
    }

    public function test_maintenance_room_gives_a_clear_reason(): void
    {
        [$admin, $room1, , $guest] = $this->setupHotel();
        $room1->update(['status' => 'maintenance']);

        $this->actingAs($admin)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [
                ['room_id' => $room1->id, 'adults' => 1],
            ],
        ])->assertSessionHasErrors(['rooms']);

        $this->assertStringContainsString('mirembajtje', session('errors')->first('rooms'));
        $this->assertCount(0, Reservation::all());
    }

    /** A stay with no manually-entered price must default to the SEASONAL rate, not base_price. */
    public function test_reservation_without_manual_price_uses_seasonal_rate(): void
    {
        [$admin, $room1, , $guest] = $this->setupHotel(); // base_price 100
        $ci = now()->addDays(3)->toDateString();
        $co = now()->addDays(5)->toDateString(); // 2 nights
        // A high season covering the stay with a per-type rate of 150.
        $season = Season::create([
            'name' => 'High',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'priority' => 100,
        ]);
        $season->rates()->create(['room_type_id' => $room1->room_type_id, 'price' => 150]);

        $this->actingAs($admin)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => $ci,
            'check_out_date' => $co,
            'rooms' => [
                ['room_id' => $room1->id, 'adults' => 1, 'children' => 0, 'total_amount' => null],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Seasonal 150 × 2 nights = 300, NOT base 100 × 2 = 200.
        $this->assertEqualsWithDelta(300.0, (float) Reservation::first()->total_amount, 0.01);
    }

    /** The quote endpoint prices server-side from RoomPricing and ignores any client-sent amount. */
    public function test_quote_endpoint_is_server_computed_and_ignores_client_price(): void
    {
        [$admin, $room1, , ] = $this->setupHotel();
        $season = Season::create([
            'name' => 'High',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'priority' => 100,
        ]);
        $season->rates()->create(['room_type_id' => $room1->room_type_id, 'price' => 150]);

        $this->actingAs($admin)->getJson(route('reservations.quote', [
            'room_id' => $room1->id,
            'check_in' => now()->addDays(3)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
            'total' => 5, // a hostile client-supplied price must be ignored
        ]))->assertOk()->assertJson(['nights' => 2, 'total' => 300]);
    }

    public function test_admin_can_back_date_a_reservation(): void
    {
        [$admin, $room1, , $guest] = $this->setupHotel();

        $this->actingAs($admin)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->subDays(5)->toDateString(),
            'check_out_date' => now()->subDays(2)->toDateString(),
            'rooms' => [
                ['room_id' => $room1->id, 'adults' => 1],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(1, Reservation::all());
    }
}
