<?php
namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_returns_per_day_free_room_counts(): void
    {
        $type = RoomType::create(['name' => 'Double', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);
        $a = Room::create(['room_type_id' => $type->id, 'room_number' => 'A', 'floor' => 1, 'status' => 'available']);
        Room::create(['room_type_id' => $type->id, 'room_number' => 'B', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@y.local', 'phone' => '1']);
        $admin = User::factory()->create();

        $in = today()->addDays(5)->toDateString();
        $out = today()->addDays(7)->toDateString(); // nights +5, +6 occupied on room A
        Reservation::create([
            'room_id' => $a->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => $in, 'check_out_date' => $out, 'status' => 'confirmed', 'total_amount' => 160, 'adults' => 2,
        ]);
        // A checked_out reservation must NOT block (matches isRoomAvailable) — covers today+1.
        Reservation::create([
            'room_id' => $a->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => today()->addDays(1)->toDateString(), 'check_out_date' => today()->addDays(2)->toDateString(),
            'status' => 'checked_out', 'total_amount' => 80, 'adults' => 1,
        ]);

        $res = $this->getJson(route('website.book.availability', [
            'room_type_id' => $type->id,
            'from' => today()->toDateString(),
            'to' => today()->addDays(10)->toDateString(),
        ]))->assertOk();

        $res->assertJsonPath('total', 2);
        $res->assertJsonPath("days.{$in}", 1);                              // one room left on the occupied night
        $res->assertJsonPath('days.' . today()->addDays(6)->toDateString(), 1);
        $res->assertJsonPath("days.{$out}", 2);                             // checkout day free again
        $res->assertJsonPath('days.' . today()->addDays(1)->toDateString(), 2); // a free day
    }
}
