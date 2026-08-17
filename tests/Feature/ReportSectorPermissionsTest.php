<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportSectorPermissionsTest extends TestCase
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

    public function test_receptionist_sees_operations_and_guests_but_no_money(): void
    {
        $receptionist = $this->userWithRole('receptionist');

        $this->actingAs($receptionist)->get(route('reports.index'))->assertOk();
        $this->actingAs($receptionist)->get(route('reports.roomStatus'))->assertOk();
        $this->actingAs($receptionist)->get(route('reports.guests'))->assertOk();
        // The complaint that started plan #724: no financial reports for the desk.
        $this->actingAs($receptionist)->get(route('reports.outstanding'))->assertForbidden();
        $this->actingAs($receptionist)->get(route('reports.shifts'))->assertForbidden();
        // And no revenue analytics either.
        $this->actingAs($receptionist)->get(route('reports.executive'))->assertForbidden();
    }

    public function test_manager_and_finance_roles_see_the_money_sectors(): void
    {
        $manager = $this->userWithRole('manager');
        $this->actingAs($manager)->get(route('reports.outstanding'))->assertOk();
        $this->actingAs($manager)->get(route('reports.executive'))->assertOk();
        $this->actingAs($manager)->get(route('reports.roomStatus'))->assertOk();

        $finance = $this->userWithRole('finance');
        $this->actingAs($finance)->get(route('reports.outstanding'))->assertOk();
        $this->actingAs($finance)->get(route('reports.executive'))->assertOk();
        // Finance staff has no operations sector — the split cuts both ways.
        $this->actingAs($finance)->get(route('reports.roomStatus'))->assertForbidden();
    }

    public function test_roles_without_any_sector_cannot_open_reports_at_all(): void
    {
        $housekeeping = $this->userWithRole('housekeeping');

        $this->actingAs($housekeeping)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($housekeeping)->get(route('reports.roomStatus'))->assertForbidden();
    }

    public function test_legacy_view_reports_umbrella_still_opens_every_sector(): void
    {
        // Un-migrated tenants hold only the old umbrella — they must keep
        // working until roles:sync-definitions runs (the rollout guarantee).
        $legacyRole = Role::findOrCreate('legacy-reporter', 'web');
        $legacyRole->syncPermissions([Permission::findOrCreate('view_reports', 'web')]);
        $user = User::factory()->create();
        $user->assignRole('legacy-reporter');

        $this->actingAs($user)->get(route('reports.index'))->assertOk();
        $this->actingAs($user)->get(route('reports.outstanding'))->assertOk();
        $this->actingAs($user)->get(route('reports.roomStatus'))->assertOk();
        $this->actingAs($user)->get(route('reports.executive'))->assertOk();
    }

    public function test_sync_definitions_dry_run_changes_nothing_and_apply_reconciles_drift(): void
    {
        $tenantId = \App\Models\Tenant::query()->sole()->id;
        $role = Role::findByName('receptionist', 'web');
        $before = $role->permissions->pluck('name')->sort()->values();

        $this->artisan('roles:sync-definitions', ['--dry-run' => true, '--tenant' => $tenantId])
            ->assertSuccessful();
        $role->refresh()->unsetRelation('permissions');
        $this->assertEquals($before, $role->permissions->pluck('name')->sort()->values());

        // Simulate drift: strip a permission, then a real run restores it.
        $role->revokePermissionTo('view_reports_operations');
        $this->artisan('roles:sync-definitions', ['--tenant' => $tenantId])->assertSuccessful();
        $role->refresh()->unsetRelation('permissions');
        $this->assertTrue($role->hasPermissionTo('view_reports_operations'));
        $this->assertEquals($before, $role->permissions->pluck('name')->sort()->values());
    }
}
