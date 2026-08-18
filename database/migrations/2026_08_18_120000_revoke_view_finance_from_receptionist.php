<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Renato (2026-08-18, after impersonating the role live): the desk does
     * not see the Financa module at all. This revokes the grant the 2026-07-14
     * backfill gave every receptionist role, so fresh installs and already-
     * migrated tenants both match TenantRoleService::definitions() without
     * waiting for roles:sync-definitions. Checkout money is unaffected — the
     * folio posts to reservations.payment (update_reservations).
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Name-only lookup is deliberate: a global rollout across every
        // tenant's receptionist role, mirroring the 2026-07-14 backfill.
        Role::query()
            ->where('name', 'receptionist')
            ->where('guard_name', 'web')
            ->each(fn (Role $role) => $role->revokePermissionTo('view_finance'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()
            ->where('name', 'receptionist')
            ->where('guard_name', 'web')
            ->each(fn (Role $role) => $role->givePermissionTo('view_finance'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
