<?php

namespace Tests\Feature;

use App\Models\CleaningTask;
use App\Models\Guest;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantBillingService;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The WRITE surface of tenant isolation: an admin of hotel A must not be able
 * to UPDATE or DELETE any record of hotel B, a forged session tenant must be
 * ignored, and roles/permissions must never bleed across hotels.
 */
class TenantMutationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $home;

    private Tenant $foreign;

    private User $admin;

    /** @var array<string, \Illuminate\Database\Eloquent\Model> */
    private array $foreignRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        $context = app(TenantContext::class);

        $this->home = Tenant::query()->sole();
        app(TenantRoleService::class)->provision($this->home);

        $this->foreign = Tenant::factory()->create(['name' => 'Hotel Foreign']);
        app(TenantBillingService::class)->provision($this->foreign, enableAll: true);
        app(TenantRoleService::class)->provision($this->foreign);

        $context->set($this->home);
        $this->admin = User::factory()->create(['current_tenant_id' => $this->home->id]);
        $this->admin->assignRole('admin');

        $context->set($this->foreign);
        $type = RoomType::create(['name' => 'F-Type', 'base_price' => 90, 'max_occupancy' => 2, 'amenities' => []]);
        $room = Room::create(['room_type_id' => $type->id, 'room_number' => 'F1', 'floor' => 1, 'status' => 'available']);
        $guest = Guest::create(['first_name' => 'For', 'last_name' => 'Eign', 'email' => 'foreign@example.test']);
        $staff = User::factory()->create();
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $staff->id,
            'check_in_date' => today()->addDays(2)->toDateString(),
            'check_out_date' => today()->addDays(4)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 180,
            'adults' => 2,
        ]);
        $task = CleaningTask::create(['room_id' => $room->id, 'type' => 'checkout_clean', 'status' => 'pending']);
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $menuItem = MenuItem::create(['menu_category_id' => $category->id, 'name' => 'Uje', 'price' => 2, 'is_available' => true]);
        $context->clear();

        $this->foreignRecords = compact('type', 'room', 'guest', 'reservation', 'task', 'menuItem');
    }

    public function test_admin_cannot_update_or_delete_records_of_another_hotel(): void
    {
        $r = $this->foreignRecords;

        $attempts = [
            ['put', route('rooms.update', $r['room']->id)],
            ['delete', route('rooms.destroy', $r['room']->id)],
            ['put', route('guests.update', $r['guest']->id)],
            ['delete', route('guests.destroy', $r['guest']->id)],
            ['put', route('reservations.update', $r['reservation']->id)],
            ['delete', route('reservations.destroy', $r['reservation']->id)],
            ['patch', route('housekeeping.status', $r['task']->id)],
            ['put', route('settings.menu-items.update', $r['menuItem']->id)],
        ];

        foreach ($attempts as [$method, $url]) {
            $this->actingAs($this->admin)
                ->{$method}($url, [])
                ->assertNotFound();
        }

        // Nothing changed or vanished on the foreign hotel.
        $this->assertSame('confirmed', Reservation::withoutGlobalScopes()->findOrFail($r['reservation']->id)->status);
        $this->assertNotNull(Room::withoutGlobalScopes()->find($r['room']->id));
        $this->assertNotNull(Guest::withoutGlobalScopes()->find($r['guest']->id));
        $this->assertNotNull(MenuItem::withoutGlobalScopes()->find($r['menuItem']->id));
    }

    public function test_forged_session_tenant_id_of_a_non_member_is_ignored(): void
    {
        // The admin belongs ONLY to the home hotel; smuggling the foreign
        // tenant's id into the session must not switch the context.
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->foreign->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenant.id', $this->home->id)
                ->where('tenant.name', $this->home->name));

        // And the foreign hotel's records still 404 with the forged session.
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->foreign->id])
            ->get(route('guests.show', $this->foreignRecords['guest']->id))
            ->assertNotFound();
    }

    public function test_roles_and_permissions_do_not_bleed_between_hotels(): void
    {
        $context = app(TenantContext::class);

        // The same person: admin at HOME, plain member (no role) at FOREIGN.
        $context->set($this->foreign);
        $this->admin->tenants()->syncWithoutDetaching([
            $this->foreign->id => ['is_owner' => false, 'is_active' => true],
        ]);
        $context->clear();

        // Session pinned to HOME → full admin payload.
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->home->id])
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenant.id', $this->home->id)
                ->where('auth.user.role', 'admin'));

        // Session switched to FOREIGN (a real membership) → NO admin role there.
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->foreign->id])
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenant.id', $this->foreign->id)
                ->where('auth.user.role', null)
                ->where('auth.user.permissions', []));
    }

    public function test_unique_validation_is_scoped_per_hotel(): void
    {
        // HOME already has room F1? No — F1 belongs to FOREIGN. The home admin
        // must be able to reuse the SAME room number for their own hotel.
        $this->actingAs($this->admin)
            ->withSession(['tenant_id' => $this->home->id])
            ->post(route('rooms.store'), [
                'room_number' => 'F1',
                'room_type_id' => tap(app(TenantContext::class))->set($this->home)
                    ->run($this->home, fn () => RoomType::create([
                        'name' => 'H-Type', 'base_price' => 70, 'max_occupancy' => 2, 'amenities' => [],
                    ]))->id,
                'floor' => 1,
                'status' => 'available',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            2,
            Room::withoutGlobalScopes()->where('room_number', 'F1')->count(),
            'The same room number must be allowed once per hotel.',
        );
    }
}
