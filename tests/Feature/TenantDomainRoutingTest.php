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
use App\Support\TrustedHostPatterns;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TenantDomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_www_requests_are_permanently_redirected_to_the_canonical_domain(): void
    {
        TenantDomain::query()->create([
            'tenant_id' => Tenant::query()->sole()->id,
            'domain' => 'villamucho.com',
            'is_primary' => false,
        ]);

        $this->get('https://www.villamucho.com/rooms?adults=2')
            ->assertStatus(308)
            ->assertRedirect('https://villamucho.com/rooms?adults=2');
    }

    public function test_unknown_www_host_is_not_an_open_redirect(): void
    {
        $this->get('https://www.unregistered-hotel.test/rooms')
            ->assertNotFound();
    }

    public function test_legacy_exact_www_domain_remains_reachable_without_creating_a_double_www_trust_pattern(): void
    {
        TenantDomain::query()->create([
            'tenant_id' => Tenant::query()->sole()->id,
            'domain' => 'www.legacy-hotel.test',
            'is_primary' => false,
        ]);

        $this->get('https://www.legacy-hotel.test/')->assertOk();

        $patterns = TrustedHostPatterns::all();
        $this->assertContains('^www\.legacy\-hotel\.test$', $patterns);
        $this->assertNotContains('^www\.www\.legacy\-hotel\.test$', $patterns);
    }

    public function test_super_admin_domain_input_is_stored_without_a_leading_www_alias(): void
    {
        config([
            'lora.control_panel_url' => 'https://admin.lorapms.test',
            'lora.control_panel_hosts' => ['admin.lorapms.test'],
            'lora.dedicated_control_panel_hosts' => ['admin.lorapms.test'],
        ]);
        $tenant = Tenant::query()->sole();
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($superAdmin)
            ->post("https://admin.lorapms.test/super-admin/tenants/{$tenant->id}/domains", [
                'domain' => 'https://www.canonical-hotel.test/some/path',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_domains', [
            'tenant_id' => $tenant->id,
            'domain' => 'canonical-hotel.test',
        ]);
        $this->assertDatabaseMissing('tenant_domains', ['domain' => 'www.canonical-hotel.test']);
    }

    public function test_trusted_host_patterns_are_exact_and_include_registered_domains(): void
    {
        $this->makeOtherHotel('trusted-hotel.test');
        config(['lora.additional_trusted_hosts' => ['health.internal.test']]);

        $patterns = TrustedHostPatterns::all();
        $matches = static fn (string $host): bool => collect($patterns)
            ->contains(static fn (string $pattern): bool => preg_match('/'.$pattern.'/i', $host) === 1);

        $this->assertTrue($matches('trusted-hotel.test'));
        $this->assertTrue($matches('www.trusted-hotel.test'));
        $this->assertTrue($matches('health.internal.test'));
        $this->assertFalse($matches('evil-trusted-hotel.test'));
        $this->assertFalse($matches('attacker.test'));
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

    public function test_registered_domain_overrides_stale_selection_for_a_multi_hotel_user(): void
    {
        $home = Tenant::query()->sole();
        $other = $this->makeOtherHotel();
        $staff = $this->makeStaffFor($home);
        $other->users()->syncWithoutDetaching([
            $staff->id => ['is_owner' => false, 'is_active' => true],
        ]);

        $this->actingAs($staff)
            ->withSession(['tenant_id' => $home->id])
            ->get('https://hotelb.test/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenant.id', $other->id)
                ->where('tenant.name', $other->name));
    }

    public function test_hotel_login_stays_on_that_hotels_tenant_on_the_next_request(): void
    {
        $home = Tenant::query()->sole();
        $other = $this->makeOtherHotel();
        $staff = $this->makeStaffFor($home);
        $other->users()->syncWithoutDetaching([
            $staff->id => ['is_owner' => false, 'is_active' => true],
        ]);

        $this->post('https://hotelb.test/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($staff);

        $this->get('https://hotelb.test/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenant.id', $other->id)
                ->where('tenant.name', $other->name));
    }

    public function test_super_admin_can_use_the_registered_hotel_host(): void
    {
        $home = Tenant::query()->sole();
        $other = $this->makeOtherHotel();
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'current_tenant_id' => $home->id,
        ]);

        $this->actingAs($superAdmin)
            ->withSession(['tenant_id' => $home->id])
            ->get('https://hotelb.test/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenant.id', $other->id));
    }

    public function test_back_office_rejects_another_hotels_member_and_an_unregistered_host(): void
    {
        $home = Tenant::query()->sole();
        $other = $this->makeOtherHotel();
        $staff = $this->makeStaffFor($home);
        $staff->tenants()->syncWithoutDetaching([
            $other->id => ['is_owner' => false, 'is_active' => false],
        ]);

        $this->actingAs($staff)
            ->withSession(['tenant_id' => $home->id])
            ->get('https://hotelb.test/dashboard')
            ->assertNotFound();

        $this->actingAs($staff)
            ->withSession(['tenant_id' => $home->id])
            ->get('https://unknown-hotel.test/dashboard')
            ->assertNotFound();
    }
}
