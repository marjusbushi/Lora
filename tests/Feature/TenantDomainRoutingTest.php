<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\TenantBillingService;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TenantDomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_www_requests_are_permanently_redirected_to_the_canonical_domain(): void
    {
        $this->get('https://www.villamucho.com/rooms?adults=2')
            ->assertStatus(308)
            ->assertRedirect('https://villamucho.com/rooms?adults=2');
    }

    public function test_admin_domain_can_resolve_the_hotel_before_authentication(): void
    {
        $tenant = Tenant::query()->sole();

        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'admin.villamucho.com',
            'is_primary' => false,
        ]);

        $this->get('https://admin.villamucho.com/login')->assertOk();
    }

    /**
     * Builds a second hotel with its own public domain, one bookable room,
     * and every module enabled — the "hotel B" of the cross-tenant scenarios.
     */
    private function makeOtherHotel(string $domain = 'hotelb.test'): Tenant
    {
        $other = Tenant::factory()->create(['name' => 'Hotel B']);
        app(TenantBillingService::class)->provision($other, enableAll: true);
        app(TenantRoleService::class)->provision($other);

        TenantDomain::query()->create([
            'tenant_id' => $other->id,
            'domain' => $domain,
            'is_primary' => true,
        ]);

        $context = app(TenantContext::class);
        $context->set($other);
        $type = RoomType::create(['name' => 'Suite B', 'base_price' => 150, 'max_occupancy' => 2, 'amenities' => []]);
        Room::create(['room_type_id' => $type->id, 'room_number' => 'B1', 'floor' => 1, 'status' => 'available']);
        $context->clear();

        return $other;
    }

    /** A logged-in staff member of the given hotel, session pinned to it. */
    private function makeStaffFor(Tenant $tenant): User
    {
        $context = app(TenantContext::class);

        $context->set($tenant);
        $staff = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $tenant->users()->syncWithoutDetaching([
            $staff->id => ['is_owner' => false, 'is_active' => true],
        ]);
        $context->clear();

        return $staff;
    }

    public function test_public_site_belongs_to_the_host_even_for_logged_in_staff_of_another_hotel(): void
    {
        $home = Tenant::query()->sole();

        app(TenantContext::class)->set($home);
        RoomType::create(['name' => 'Standard A', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);
        app(TenantContext::class)->clear();

        $this->makeOtherHotel();
        $staff = $this->makeStaffFor($home);

        $this->actingAs($staff)
            ->withSession(['tenant_id' => $home->id])
            ->get('https://hotelb.test/rooms')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Website/Rooms')
                ->has('roomTypes', 1)
                ->where('roomTypes.0.name', 'Suite B'));
    }

    public function test_public_booking_lands_on_the_host_hotel_not_the_visitors_hotel(): void
    {
        $home = Tenant::query()->sole();
        $other = $this->makeOtherHotel();
        $staff = $this->makeStaffFor($home);

        app(TenantContext::class)->set($other);
        $roomId = Room::query()->sole()->id;
        app(TenantContext::class)->clear();

        $this->actingAs($staff)
            ->withSession(['tenant_id' => $home->id])
            ->post('https://hotelb.test/book', [
                'room_id' => $roomId,
                'check_in' => today()->addDays(3)->toDateString(),
                'check_out' => today()->addDays(5)->toDateString(),
                'first_name' => 'Ana',
                'last_name' => 'Berisha',
                'email' => 'ana@example.test',
                'phone' => '+355690000000',
                'adults' => 2,
            ])
            ->assertRedirect();

        $reservation = Reservation::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame($other->id, $reservation->tenant_id);

        // Each hotel gets its OWN technical booking identity — never a shared one.
        $creator = User::withoutGlobalScopes()->withTrashed()->findOrFail($reservation->created_by);
        $this->assertSame("system+t{$other->id}@lora.local", $creator->email);
    }

    public function test_unknown_host_on_public_routes_is_404_even_for_logged_in_users(): void
    {
        $staff = $this->makeStaffFor(Tenant::query()->sole());

        $this->actingAs($staff)
            ->get('https://unknown-hotel.test/rooms')
            ->assertNotFound();
    }
}
