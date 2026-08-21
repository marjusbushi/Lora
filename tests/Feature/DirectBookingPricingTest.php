<?php

namespace Tests\Feature;

use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectBookingPricingTest extends TestCase
{
    use RefreshDatabase;

    private function room(): Room
    {
        $type = RoomType::create([
            'name' => 'Smart Deluxe',
            'description' => 'Direct price test',
            'base_price' => 80,
            'max_occupancy' => 2,
            'amenities' => ['Wi-Fi'],
        ]);

        return Room::create([
            'room_type_id' => $type->id,
            'room_number' => 'D10',
            'floor' => 1,
            'status' => 'available',
        ]);
    }

    public function test_website_quote_applies_configured_discount_to_smart_price(): void
    {
        Setting::set('pricing_programs.direct_discount_enabled', true, 'boolean');
        Setting::set('pricing_programs.direct_discount_pct', 10, 'number');
        $room = $this->room();
        $date = today()->addDays(4);
        RateOverride::create([
            'room_type_id' => $room->room_type_id,
            'date' => $date->toDateString(),
            'price' => 100,
        ]);

        $response = $this->postJson(route('website.book.check'), [
            'check_in' => $date->toDateString(),
            'check_out' => $date->copy()->addDay()->toDateString(),
            'room_type_id' => $room->room_type_id,
        ])->assertOk();
        $response
            ->assertJsonPath('room_types.0.smart_total_price', 100)
            ->assertJsonPath('room_types.0.direct_discount_pct', 10)
            ->assertJsonPath('room_types.0.direct_discount_amount', 10)
            ->assertJsonPath('room_types.0.total_price', 90);
    }

    public function test_booking_recalculates_and_persists_the_discount_snapshot_server_side(): void
    {
        Setting::set('pricing_programs.direct_discount_enabled', true, 'boolean');
        Setting::set('pricing_programs.direct_discount_pct', 10, 'number');
        $room = $this->room();
        $checkIn = today()->addDays(6);
        RateOverride::create([
            'room_type_id' => $room->room_type_id,
            'date' => $checkIn->toDateString(),
            'price' => 100,
        ]);

        $this->post(route('website.book.submit'), [
            'selections' => [['room_type_id' => $room->room_type_id, 'quantity' => 1]],
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDay()->toDateString(),
            'first_name' => 'Direct',
            'last_name' => 'Guest',
            'email' => 'direct@example.test',
            'phone' => '+355690000000',
            'adults' => 2,
            'children' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reservation = Reservation::firstOrFail();
        $this->assertSame(100.0, (float) $reservation->rate_before_discount);
        $this->assertSame(10.0, (float) $reservation->direct_discount_pct);
        $this->assertSame(10.0, (float) $reservation->direct_discount_amount);
        $this->assertSame(90.0, (float) $reservation->total_amount);
    }
}
