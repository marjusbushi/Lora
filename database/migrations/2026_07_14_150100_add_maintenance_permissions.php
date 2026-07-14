<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(['view', 'create', 'update', 'delete'])
            ->mapWithKeys(function (string $action) {
                $name = "{$action}_maintenance";

                return [$name => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])];
            });

        Tenant::query()->active()->each(function (Tenant $tenant) use ($permissions) {
            app(TenantContext::class)->run($tenant, function () use ($tenant, $permissions) {
                $grants = [
                    'admin' => $permissions->keys()->all(),
                    'manager' => $permissions->keys()->all(),
                    'receptionist' => ['view_maintenance', 'create_maintenance', 'update_maintenance'],
                    'housekeeping' => ['view_maintenance', 'create_maintenance'],
                    'maintenance' => ['view_maintenance', 'create_maintenance', 'update_maintenance'],
                ];

                foreach ($grants as $roleName => $names) {
                    $role = Role::firstOrCreate([
                        'team_id' => $tenant->id,
                        'name' => $roleName,
                        'guard_name' => 'web',
                    ]);
                    $role->givePermissionTo($names);
                }
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', [
            'view_maintenance', 'create_maintenance', 'update_maintenance', 'delete_maintenance',
        ])->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
