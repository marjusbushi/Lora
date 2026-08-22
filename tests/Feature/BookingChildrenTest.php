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

    private function room(int $maxOcc = 4, ?int $maxChildren = null): Room
    {
        $type = RoomType::create([
            'name' => 'Fam', 'base_price' => 100, 'max_occupancy' => $maxOcc,
            // Pasqyron backfill-in e migrimit (kapaciteti - 1) kur s'jepet.
            'max_children' => $maxChildren ?? max(0, $maxOcc - 1),
            'amenities' => [],
        ]);

        return Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
    }

    private function payload(Room $room, int $adults, int $children): array
    {
        return [
            'selections' => [['room_type_id' => $room->room_type_id, 'quantity' => 1]],
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

        // Capacity failures are now VALIDATION errors (selections) so the booking wizard
        // preserves everything the guest typed instead of resetting to step 1.
        $this->post(route('website.book.submit'), $this->payload($room, 3, 3)) // total 6 > 4
            ->assertSessionHasErrors('selections');

        $this->assertSame(0, Reservation::count());
    }

    /** Task #428: kufiri i fëmijëve PER tipologji imponohet server-side, jo vetëm totali. */
    public function test_children_above_the_room_children_cap_are_rejected(): void
    {
        $room = $this->room(4, 1); // 4 vende gjithsej, por vetëm 1 mund të jetë fëmijë

        $this->post(route('website.book.submit'), $this->payload($room, 2, 2)) // 4 veta OK, por 2 fëmijë > 1
            ->assertSessionHasErrors('selections');

        $this->assertSame(0, Reservation::count());
    }

    /** Task #428: karroca ka nevojë për max_children — payload-i i disponibilitetit e mbart. */
    public function test_availability_payload_carries_max_children(): void
    {
        $this->room(4, 2);

        $this->post(route('website.book.check'), [
            'check_in' => today()->addDays(3)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
        ])->assertOk()->assertJsonPath('room_types.0.max_children', 2);
    }
}
