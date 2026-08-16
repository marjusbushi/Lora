<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Moduli Plazhi: admin merr gjithçka, manager + receptionist punojnë
 * me rezervimet e çadrave por nuk fshijnë dot strukturën (delete = admin).
 */
return new class extends Migration
{
    private const PERMISSIONS = ['view_beach', 'create_beach', 'update_beach', 'delete_beach'];

    private const STAFF_PERMISSIONS = ['view_beach', 'create_beach', 'update_beach'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Tenant::query()->active()->each(function (Tenant $tenant) {
            app(TenantContext::class)->run($tenant, function () use ($tenant) {
                $admin = Role::firstOrCreate([
                    'team_id' => $tenant->id, 'name' => 'admin', 'guard_name' => 'web',
                ]);
                $admin->givePermissionTo(self::PERMISSIONS);

                foreach (['manager', 'receptionist'] as $roleName) {
                    $role = Role::firstOrCreate([
                        'team_id' => $tenant->id, 'name' => $roleName, 'guard_name' => 'web',
                    ]);
                    $role->givePermissionTo(self::STAFF_PERMISSIONS);
                }
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
