<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TenantRoleService
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, list<string>|string> */
    public static function definitions(): array
    {
        return [
            'admin' => '*',
            'manager' => [
                'view_rooms', 'create_rooms', 'update_rooms', 'delete_rooms',
                'view_reservations', 'create_reservations', 'update_reservations', 'delete_reservations',
                'view_guests', 'create_guests', 'update_guests', 'delete_guests',
                'view_housekeeping', 'create_housekeeping', 'update_housekeeping', 'delete_housekeeping',
                'view_maintenance', 'create_maintenance', 'update_maintenance', 'delete_maintenance',
                'view_pos_orders', 'create_pos_orders', 'update_pos_orders', 'delete_pos_orders',
                'open_pos_shift', 'close_pos_shift', 'close_any_pos_shift',
                'view_reports',
                // Finance: everything operational EXCEPT bank visibility,
                // finance settings and deleting records (owner-only).
                'view_finance', 'create_payment', 'pay_bills', 'manage_transfers',
                'manage_invoices', 'manage_bills', 'manage_suppliers',
                'view_inventory', 'manage_inventory',
            ],
            'receptionist' => [
                'view_rooms', 'update_rooms',
                'view_reservations', 'create_reservations', 'update_reservations', 'delete_reservations',
                'view_guests', 'create_guests', 'update_guests',
                'view_maintenance', 'create_maintenance', 'update_maintenance',
                'view_pos_orders', 'create_pos_orders', 'update_pos_orders',
                'open_pos_shift', 'close_pos_shift',
                'view_reports',
                // Finance: sees the arka and records incoming payments only.
                'view_finance', 'create_payment',
            ],
            'housekeeping' => [
                'view_rooms', 'update_rooms',
                'view_housekeeping', 'create_housekeeping', 'update_housekeeping',
                'view_maintenance', 'create_maintenance',
            ],
            'maintenance' => [
                'view_rooms', 'update_rooms',
                'view_maintenance', 'create_maintenance', 'update_maintenance',
            ],
            'pos_staff' => [
                'view_pos_orders', 'create_pos_orders', 'update_pos_orders',
                'open_pos_shift', 'close_pos_shift',
                'view_rooms',
            ],
        ];
    }

    /** @return list<string> */
    public static function permissionNames(): array
    {
        $resources = [
            'rooms' => ['view', 'create', 'update', 'delete'],
            'reservations' => ['view', 'create', 'update', 'delete'],
            'guests' => ['view', 'create', 'update', 'delete'],
            'housekeeping' => ['view', 'create', 'update', 'delete'],
            'maintenance' => ['view', 'create', 'update', 'delete'],
            'pos_orders' => ['view', 'create', 'update', 'delete'],
            'pos_shift' => ['open', 'close', 'close_any'],
            'reports' => ['view'],
            'settings' => ['view', 'update'],
            'users' => ['view', 'create', 'update', 'delete'],
        ];

        // Finance permissions (Marjus's decision: managed in the GLOBAL roles
        // system, never inside the Finance module). Deny-by-default per verb.
        $finance = [
            'view_finance', 'view_bank_accounts', 'create_payment', 'pay_bills',
            'manage_transfers', 'manage_invoices', 'manage_bills',
            'manage_suppliers', 'manage_finance_settings', 'delete_finance_records',
            'view_inventory', 'manage_inventory',
        ];

        return collect($resources)
            ->flatMap(fn (array $actions, string $resource) => collect($actions)
                ->map(fn (string $action) => "{$action}_{$resource}"))
            ->merge($finance)
            ->values()
            ->all();
    }

    public function provision(Tenant $tenant, ?User $owner = null): void
    {
        $this->context->run($tenant, function () use ($owner) {
            $permissions = collect(self::permissionNames())
                ->mapWithKeys(function (string $name) {
                    $permission = Permission::firstOrCreate([
                        'name' => $name,
                        'guard_name' => 'web',
                    ]);

                    return [$name => $permission];
                });

            foreach (self::definitions() as $name => $rolePermissions) {
                $role = Role::findOrCreate($name, 'web');
                $role->syncPermissions($rolePermissions === '*'
                    ? $permissions->values()
                    : $permissions->only($rolePermissions)->values());
            }

            if ($owner) {
                $owner->unsetRelation('roles')->assignRole('admin');
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
