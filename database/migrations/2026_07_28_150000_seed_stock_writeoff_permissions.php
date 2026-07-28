<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Stock write-offs (damaged/lost/expired goods) are finance's and the
     * admin's call. Finance also gains view_inventory so the Artikujt page
     * opens for them — but NOT manage_inventory: they remove stock with an
     * audited reason, they don't edit articles.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Fresh databases have not run the seeder — create every permission
        // granted below before touching the roles.
        foreach (['view_inventory', 'manage_stock_writeoffs'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::where('name', 'admin')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo('manage_stock_writeoffs'));
        Role::where('name', 'finance')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(['view_inventory', 'manage_stock_writeoffs']));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // view_inventory predates this migration — only its finance grant is undone.
        Role::where('name', 'finance')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->revokePermissionTo('view_inventory'));
        Permission::where('name', 'manage_stock_writeoffs')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
