<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAddonTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->orderBy('id')->firstOrFail();
    }

    public function test_existing_tenant_is_grandfathered_and_revoke_grant_roundtrips(): void
    {
        $this->admin();
        $tenant = $this->tenant();
        // The base tenant predates the grandfather migration -> already granted.
        $this->assertTrue($tenant->hasAddon('finance'));

        $tenant->revokeAddon('finance');
        $this->assertFalse($tenant->fresh()->hasAddon('finance'));

        $tenant->fresh()->grantAddon('finance');
        $this->assertTrue($tenant->fresh()->hasAddon('finance'));
    }

    public function test_finance_routes_are_locked_without_the_addon_and_open_with_it(): void
    {
        $this->withoutVite();
        $admin = $this->admin();
        $this->tenant()->revokeAddon('finance'); // hiqe grandfather-in për test

        // pa addon: 403 kudo — edhe për adminin e hotelit
        $this->actingAs($admin)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('finance.transfers.store'), [])->assertForbidden();
        $this->actingAs($admin)->put(route('settings.currencies'), [])->assertForbidden();

        // me addon: hapet normalisht
        $this->tenant()->grantAddon('finance');
        $this->actingAs($admin)->get(route('finance.index'))->assertOk();
    }

    public function test_grandfather_migration_granted_existing_tenants(): void
    {
        // Reset the tenant, then exercise the real migration implementation.
        $tenant = tap($this->tenant())->update(['metadata' => null]);
        $migration = require database_path('migrations/2026_07_13_170000_grant_finance_addon_to_existing_tenants.php');

        $migration->up();

        $this->assertTrue($tenant->fresh()->hasAddon('finance'));
    }

    public function test_grandfather_migration_restores_tenant_metadata_on_rollback(): void
    {
        $tenant = $this->tenant();
        $before = [
            'billing_access' => ['modules' => ['core' => true]],
            'custom' => ['keep' => 'unchanged'],
        ];
        $tenant->update(['metadata' => $before]);
        $otherBefore = [
            'addons' => ['inventory'],
            'custom' => ['keep' => 'other tenant'],
        ];
        $otherTenant = Tenant::factory()->create(['metadata' => $otherBefore]);

        $migration = require database_path('migrations/2026_07_13_170000_grant_finance_addon_to_existing_tenants.php');
        $migration->up();

        $this->assertSame(['finance'], $tenant->fresh()->addons());
        $this->assertSame(['inventory', 'finance'], $otherTenant->fresh()->addons());

        $migration->down();

        $this->assertSame($before, $tenant->fresh()->metadata);
        $this->assertSame($otherBefore, $otherTenant->fresh()->metadata);
    }

    public function test_addon_command_grants_lists_and_revokes(): void
    {
        $this->admin();
        $tenant = $this->tenant();

        $this->artisan('tenant:addon', ['tenant' => $tenant->slug, 'addon' => 'finance'])->assertSuccessful();
        $this->assertTrue($tenant->fresh()->hasAddon('finance'));

        $this->artisan('tenant:addon', ['tenant' => $tenant->slug, 'addon' => 'finance', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($tenant->fresh()->hasAddon('finance'));

        $this->artisan('tenant:addon', ['tenant' => $tenant->slug, 'addon' => 'garbage'])->assertFailed();
        $this->artisan('tenant:addon', ['--list' => true])->assertSuccessful();
    }

    public function test_nav_prop_carries_the_addons(): void
    {
        $this->withoutVite();
        $admin = $this->admin();
        $this->tenant()->grantAddon('finance');

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tenant.addons', ['finance']));
    }
}
