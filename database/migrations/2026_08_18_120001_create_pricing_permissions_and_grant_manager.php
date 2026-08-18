<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Lejet v2.1 (Renato 2026-08-18): pricing leaves the admin-only umbrella —
     * the manager works the module (incl. the smart calendar). Creates the two
     * permissions and grants them to every tenant's admin and manager roles at
     * deploy time, so nothing waits on roles:sync-definitions. Name-only role
     * lookup is deliberate: a global rollout, mirroring the 2026-07-14 backfill.
     */
    private const PERMISSIONS = ['view_pricing', 'update_pricing'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::query()
            ->whereIn('name', ['admin', 'manager'])
            ->where('guard_name', 'web')
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()
            ->whereIn('name', ['admin', 'manager'])
            ->where('guard_name', 'web')
            ->each(fn (Role $role) => $role->revokePermissionTo(self::PERMISSIONS));

        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
