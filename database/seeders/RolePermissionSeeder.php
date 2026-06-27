<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all permissions grouped by resource
        $permissions = [
            'rooms'        => ['view', 'create', 'update', 'delete'],
            'reservations' => ['view', 'create', 'update', 'delete'],
            'guests'       => ['view', 'create', 'update', 'delete'],
            'housekeeping' => ['view', 'create', 'update', 'delete'],
            'pos_orders'   => ['view', 'create', 'update', 'delete'],
            'reports'      => ['view'],
            'settings'     => ['view', 'update'],
            'users'        => ['view', 'create', 'update', 'delete'],
        ];

        // Create all permissions
        $allPermissions = [];
        foreach ($permissions as $resource => $actions) {
            foreach ($actions as $action) {
                $allPermissions[] = Permission::firstOrCreate([
                    'name' => "{$action}_{$resource}",
                    'guard_name' => 'web',
                ]);
            }
        }

        // Admin — full access
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($allPermissions);

        // Manager — everything except user management and settings
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->syncPermissions(
            collect($allPermissions)->filter(function ($p) {
                return ! str_contains($p->name, '_users')
                    && ! str_contains($p->name, '_settings');
            })
        );

        // Receptionist — reservations, rooms (view/update), guests
        $receptionist = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);
        $receptionist->syncPermissions([
            'view_rooms', 'update_rooms',
            'view_reservations', 'create_reservations', 'update_reservations', 'delete_reservations',
            'view_guests', 'create_guests', 'update_guests',
            'view_pos_orders', 'create_pos_orders', 'update_pos_orders',
            'view_reports',
        ]);

        // Housekeeping — rooms (view/update status only), housekeeping tasks
        $housekeeping = Role::firstOrCreate(['name' => 'housekeeping', 'guard_name' => 'web']);
        $housekeeping->syncPermissions([
            'view_rooms', 'update_rooms',
            'view_housekeeping', 'create_housekeeping', 'update_housekeeping',
        ]);

        // POS Staff — bar/restaurant orders only
        $posStaff = Role::firstOrCreate(['name' => 'pos_staff', 'guard_name' => 'web']);
        $posStaff->syncPermissions([
            'view_pos_orders', 'create_pos_orders', 'update_pos_orders',
            'view_rooms',
        ]);
    }
}
