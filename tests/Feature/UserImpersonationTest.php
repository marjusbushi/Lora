<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class UserImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_impersonates_a_receptionist_and_sees_their_world(): void
    {
        $admin = $this->userWithRole('admin');
        $receptionist = $this->userWithRole('receptionist');

        $this->actingAs($admin)
            ->post(route('users.impersonate', $receptionist))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($receptionist);
        $this->assertSame($admin->id, session('impersonator_id'));

        // The banner prop travels on every page.
        $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('impersonating.target_name', $receptionist->name)
            ->where('impersonating.admin_name', $admin->name));

        // The Lejet-v2 tie-in: while impersonating, the TARGET's permissions
        // rule — a financial report is forbidden even though the real person
        // behind the session is an admin.
        $this->get(route('reports.outstanding'))->assertForbidden();

        // Audit start row: causer = the admin, subject = the target.
        $start = AuditLog::where('action', 'user.impersonation_started')->firstOrFail();
        $this->assertSame($admin->id, $start->causer_id);
        $this->assertSame($receptionist->id, (int) $start->subject_id);
    }

    public function test_stop_restores_the_admin_with_everything(): void
    {
        $admin = $this->userWithRole('admin');
        $receptionist = $this->userWithRole('receptionist');
        $this->actingAs($admin)->post(route('users.impersonate', $receptionist));

        $this->post(route('impersonation.stop'))->assertRedirect(route('users.index'));

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('impersonator_id'));
        $this->get(route('reports.outstanding'))->assertOk(); // the admin's world is back

        $end = AuditLog::where('action', 'user.impersonation_ended')->firstOrFail();
        $this->assertSame($admin->id, $end->causer_id);
        $this->assertSame($receptionist->id, (int) $end->subject_id);
    }

    public function test_non_admins_cannot_start_an_impersonation(): void
    {
        $receptionist = $this->userWithRole('receptionist');
        $housekeeper = $this->userWithRole('housekeeping');

        $this->actingAs($receptionist)
            ->post(route('users.impersonate', $housekeeper))
            ->assertForbidden();

        $this->assertAuthenticatedAs($receptionist);
        $this->assertSame(0, AuditLog::where('action', 'like', 'user.impersonation%')->count());
    }

    public function test_admins_and_self_are_never_impersonated(): void
    {
        $admin = $this->userWithRole('admin');
        $otherAdmin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('users.impersonate', $otherAdmin))
            ->assertSessionHasErrors(['impersonate']);
        $this->actingAs($admin)->post(route('users.impersonate', $admin))
            ->assertSessionHasErrors(['impersonate']);

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_super_admin_flag_alone_blocks_impersonation(): void
    {
        $admin = $this->userWithRole('admin');
        // Not an admin ROLE — only the platform flag. The guard must still refuse.
        $platformOwner = $this->userWithRole('receptionist');
        $platformOwner->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($admin)->post(route('users.impersonate', $platformOwner))
            ->assertSessionHasErrors(['impersonate']);

        $this->assertAuthenticatedAs($admin);
    }

    public function test_stop_fails_closed_when_the_admin_has_vanished(): void
    {
        $admin = $this->userWithRole('admin');
        $receptionist = $this->userWithRole('receptionist');
        $this->actingAs($admin)->post(route('users.impersonate', $receptionist));

        // The admin is deleted mid-impersonation: restoring them is impossible,
        // so the only safe exit is a full logout.
        $admin->delete();

        $this->post(route('impersonation.stop'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_users_of_another_tenant_are_invisible_to_the_binding(): void
    {
        $admin = $this->userWithRole('admin');
        $foreign = $this->userWithRole('receptionist');
        $otherTenant = Tenant::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Hotel Tjetër', 'slug' => 'hotel-tjeter', 'currency' => 'EUR', 'timezone' => 'Europe/Tirane']);
        // Move the target's membership entirely to the other tenant.
        DB::table('tenant_user')->where('user_id', $foreign->id)->update(['tenant_id' => $otherTenant->id]);

        $this->actingAs($admin)
            ->post(route('users.impersonate', $foreign->id))
            ->assertNotFound();
    }

    public function test_nested_impersonation_is_refused(): void
    {
        $admin = $this->userWithRole('admin');
        $first = $this->userWithRole('receptionist');
        $second = $this->userWithRole('housekeeping');
        $this->actingAs($admin)->post(route('users.impersonate', $first));

        // The impersonated receptionist is not an admin, so the start route
        // itself is out of reach — nesting dies at the role gate.
        $this->post(route('users.impersonate', $second))->assertForbidden();
        $this->assertAuthenticatedAs($first);
    }

    public function test_stop_without_an_active_impersonation_is_a_harmless_redirect(): void
    {
        $receptionist = $this->userWithRole('receptionist');

        $this->actingAs($receptionist)->post(route('impersonation.stop'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($receptionist);
        $this->assertSame(0, AuditLog::where('action', 'user.impersonation_ended')->count());
    }
}
