<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingChildrenTest extends TestCase
{
    use RefreshDatabase;

    private function room(int $maxOcc = 4): Room
    {
        $type = RoomType::create(['name' => 'Fam', 'base_price' => 100, 'max_occupancy' => $maxOcc, 'amenities' => []]);

        return Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
    }

    private function payload(Room $room, int $adults, int $children): array
    {
        return [
            'room_id' => $room->id,
            'check_in' => today()->addDays(3)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
            'first_name' => 'Ana', 'last_name' => 'B', 'email' => 'a@b.local', 'phone' => '+355 69 000',
            'adults' => $adults, 'children' => $children, 'website' => '',
        ];
    }

    public function test_public_booking_stores_children(): void
    {
        $room = $this->room(4);

        $this->post(route('website.book.submit'), $this->payload($room, 2, 2))
            ->assertRedirect();

        $res = Reservation::latest('id')->first();
        $this->assertSame(2, (int) $res->children);
        $this->assertSame(2, (int) $res->adults);
    }

    public function test_over_capacity_adults_plus_children_is_rejected(): void
    {
        $room = $this->room(4);

        // Capacity failures are now VALIDATION errors (room_id) so the booking wizard
        // preserves everything the guest typed instead of resetting to step 1.
        $this->post(route('website.book.submit'), $this->payload($room, 3, 3)) // total 6 > 4
            ->assertSessionHasErrors('room_id');

        $this->assertSame(0, Reservation::count());
    }
}
