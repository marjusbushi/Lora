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

class ReservationCalendarDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_exposes_enriched_reservation_fields(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Family', 'base_price' => 100, 'max_occupancy' => 4, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Csaba', 'last_name' => 'Babai', 'email' => 'csaba@test.local', 'phone' => '+355 69 111 2222', 'nationality' => 'HU']);

        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => now()->startOfWeek()->addDay()->toDateString(),
            'check_out_date' => now()->startOfWeek()->addDays(4)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 200,
            'adults' => 2,
            'children' => 1,
            'channel' => 'booking.com',
            'channel_ref' => 'BOOK-101',
            'payment_collect' => 'property',
            'eta' => '15:30',
            'notes' => 'High floor please',
        ]);
        $reservation->payments()->create(['amount' => 75, 'method' => 'card', 'created_by' => $admin->id]);

        $this->actingAs($admin)->get(route('reservations.calendar'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Calendar')
                ->has('reservations.0', fn (AssertableInertia $r) => $r
                    ->where('adults', 2)
                    ->where('children', 1)
                    ->where('channel', 'booking.com')
                    ->where('channel_ref', 'BOOK-101')
                    ->where('payment_collect', 'property')
                    ->where('eta', '15:30')
                    ->where('paid_amount', 75)
                    ->where('created_via', Reservation::CREATED_VIA_STAFF)
                    ->where('notes', 'High floor please')
                    ->where('booking_group_id', null)
                    ->where('guest.phone', '+355691112222')
                    ->where('guest.email', 'csaba@test.local')
                    ->where('guest.nationality', 'HU')
                    ->etc()
                )
            );
    }
}
