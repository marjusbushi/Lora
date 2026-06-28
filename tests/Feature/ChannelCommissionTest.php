<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelCommissionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Room, 2: Guest} */
    private function setupHotel(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create(['name' => 'Std', 'base_price' => 80, 'max_occupancy' => 3, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'Ana', 'last_name' => 'Test', 'email' => 'ana@test.local', 'phone' => '+355 69 000 0000']);

        return [$admin, $room, $guest];
    }

    public function test_settings_saves_channel_fees_and_filters_unknown_channels(): void
    {
        [$admin] = $this->setupHotel();

        $this->actingAs($admin)->put(route('settings.financial'), [
            'tax_rate' => 20,
            'payment_methods' => ['cash'],
            'currency_symbol' => '€',
            'channel_fees' => ['booking.com' => 12, 'airbnb' => 15, 'bogus' => 99],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $fees = Setting::get('financial.channel_fees');
        $this->assertEquals(12.0, $fees['booking.com']);
        $this->assertEquals(15.0, $fees['airbnb']);
        $this->assertArrayNotHasKey('bogus', $fees);
    }

    public function test_entered_price_is_honored_and_commission_auto_computed(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();
        Setting::set('financial.channel_fees', ['booking.com' => 12], 'json');

        $this->actingAs($admin)->post(route('reservations.store'), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed',
            'adults' => 2,
            'channel' => 'booking.com',
            'total_amount' => 100, // gross price (fee included) entered by staff
        ])->assertRedirect()->assertSessionHasNoErrors();

        $res = Reservation::latest('id')->first();
        $this->assertEquals(100.0, (float) $res->total_amount);     // entered price wins over base*nights (160)
        $this->assertEquals(12.0, (float) $res->commission_amount); // 12% of 100, server-computed
    }

    public function test_manual_channel_zero_commission_and_default_price(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();
        Setting::set('financial.channel_fees', ['booking.com' => 12], 'json');

        $this->actingAs($admin)->post(route('reservations.store'), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'adults' => 1,
            // no channel => manual; no total_amount => base_price * nights
        ])->assertRedirect()->assertSessionHasNoErrors();

        $res = Reservation::latest('id')->first();
        $this->assertEquals('manual', $res->channel);
        $this->assertEquals(160.0, (float) $res->total_amount);   // 80 * 2 nights
        $this->assertEquals(0.0, (float) $res->commission_amount);
    }

    public function test_client_supplied_commission_is_ignored(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();
        Setting::set('financial.channel_fees', ['booking.com' => 12], 'json');

        $this->actingAs($admin)->post(route('reservations.store'), [
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'adults' => 1,
            'channel' => 'booking.com',
            'total_amount' => 200,
            'commission_amount' => 999, // must be ignored — server is authoritative
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(24.0, (float) Reservation::latest('id')->first()->commission_amount); // 12% of 200
    }

    public function test_index_exposes_channel_fees_to_the_page(): void
    {
        [$admin] = $this->setupHotel();
        Setting::set('financial.channel_fees', ['booking.com' => 12], 'json');

        $this->actingAs($admin)->get(route('reservations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reservations/Index')
                ->where('channelFees', ['booking.com' => 12])
            );
    }
}
