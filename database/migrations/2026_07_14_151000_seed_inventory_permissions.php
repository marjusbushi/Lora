<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = ['view_inventory', 'manage_inventory'];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['admin', 'manager'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->get()
                ->each(fn (Role $role) => $role->givePermissionTo($permissions));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['view_inventory', 'manage_inventory'] as $name) {
            Permission::where('name', $name)->where('guard_name', 'web')->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
