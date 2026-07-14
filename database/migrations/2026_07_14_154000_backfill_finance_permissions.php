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

        $permissions = [
            'view_finance',
            'view_bank_accounts',
            'create_payment',
            'pay_bills',
            'manage_transfers',
            'manage_invoices',
            'manage_bills',
            'manage_suppliers',
            'manage_finance_settings',
            'delete_finance_records',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $grants = [
            'admin' => $permissions,
            'manager' => [
                'view_finance', 'create_payment', 'pay_bills', 'manage_transfers',
                'manage_invoices', 'manage_bills', 'manage_suppliers',
            ],
            'receptionist' => ['view_finance', 'create_payment'],
        ];

        foreach ($grants as $roleName => $names) {
            Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->each(fn (Role $role) => $role->givePermissionTo($names));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally non-destructive. These permissions already exist on
        // production before this backfill, so deleting them during a release
        // rollback would remove valid role configuration owned by the hotel.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
