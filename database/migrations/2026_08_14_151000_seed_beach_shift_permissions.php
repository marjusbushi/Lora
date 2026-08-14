<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Turnet e plazhit: admin+manager hapin/mbyllin çdo turn; recepsionisti vetëm të vetin.
 * Me migrim (jo seeder) sepse prod ekzekuton vetëm migrate --force.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['open_beach_shift', 'close_beach_shift', 'close_any_beach_shift'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Tenant::query()->active()->each(function (Tenant $tenant) {
            app(TenantContext::class)->run($tenant, function () use ($tenant) {
                foreach (['admin', 'manager'] as $roleName) {
                    Role::firstOrCreate(['team_id' => $tenant->id, 'name' => $roleName, 'guard_name' => 'web'])
                        ->givePermissionTo(self::PERMISSIONS);
                }

                Role::firstOrCreate(['team_id' => $tenant->id, 'name' => 'receptionist', 'guard_name' => 'web'])
                    ->givePermissionTo(['open_beach_shift', 'close_beach_shift']);
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
