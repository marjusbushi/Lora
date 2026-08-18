<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lejet v2.1 (Renato 2026-08-18): pricing left the admin-only umbrella. The
 * manager works the module — including the smart calendar — via view_pricing/
 * update_pricing; operational roles stay out; admins lose nothing (the
 * role_or_permission gate matches their ROLE even on un-synced tenants).
 */
class PricingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(RolePermissionSeeder::class);
        $this->withoutVite();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_manager_reaches_the_pricing_module_including_the_smart_calendar(): void
    {
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)->get(route('pricing.index'))->assertOk();
        $this->actingAs($manager)->get(route('pricing.smart.index'))->assertOk();
    }

    public function test_manager_can_write_prices(): void
    {
        $manager = $this->userWithRole('manager');

        // An empty payload proves AUTHORIZATION passes: a validation redirect
        // (302 with errors) can only happen after the permission gate.
        $this->actingAs($manager)->post(route('pricing.seasons.store'), [])
            ->assertRedirect()
            ->assertSessionHasErrors();
    }

    public function test_admin_access_is_unchanged_by_the_regrouping(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->get(route('pricing.index'))->assertOk();
        $this->actingAs($admin)->get(route('pricing.smart.index'))->assertOk();
        $this->actingAs($admin)->post(route('pricing.seasons.store'), [])
            ->assertRedirect()
            ->assertSessionHasErrors();
    }

    public function test_operational_roles_stay_out_of_pricing(): void
    {
        foreach (['receptionist', 'housekeeping', 'pos_staff'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get(route('pricing.index'))
                ->assertForbidden();
        }
    }

    public function test_view_only_pricing_cannot_write_or_spend_ai_credit(): void
    {
        // Custom-role shape: can open the module, cannot change prices — and
        // the AI endpoints count as writes so view-only cannot burn credit.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('view_pricing');

        $this->actingAs($viewer)->get(route('pricing.index'))->assertOk();
        $this->actingAs($viewer)->post(route('pricing.seasons.store'), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('pricing.smart.ask'), [])->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('pricing.index'))->assertRedirect();
    }
}
