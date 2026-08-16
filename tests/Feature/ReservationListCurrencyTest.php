<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReservationListCurrencyTest extends TestCase
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

    private function reservation(Room $room, Guest $guest, User $admin): Reservation
    {
        return Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed', 'total_amount' => 160, 'adults' => 2,
        ]);
    }

    public function test_index_exposes_base_to_pricing_cross_rate_when_currencies_differ(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();

        // Saturn-like setup: accounting (base) in Lek, selling in Euro, 100 L per €.
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        Setting::set('pricing.currency', 'EUR');
        Setting::set('currencies.mode', 'manual');
        Setting::set('currencies.manual_rates', ['ALL' => 100.0], 'json');
        $this->reservation($room, $guest, $admin);

        $this->actingAs($admin)->get(route('reservations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Index')
                ->where('baseToPricingRate', 0.01)
            );
    }

    public function test_rate_is_one_when_base_and_pricing_currency_match(): void
    {
        [$admin, $room, $guest] = $this->setupHotel();
        $tenant = Tenant::query()->sole();
        app(TenantContext::class)->set($tenant);
        Setting::set('pricing.currency', $tenant->currency ?: 'EUR');
        $this->reservation($room, $guest, $admin);

        $this->actingAs($admin)->get(route('reservations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Index')
                ->where('baseToPricingRate', 1)
            );
    }
}
