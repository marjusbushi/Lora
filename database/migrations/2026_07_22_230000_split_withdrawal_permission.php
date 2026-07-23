<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Split capital-movement rights: manage_deposits stays for putting money IN
 * (finance role + admin), the new manage_withdrawals gates taking money OUT
 * (admin only). The finance role deliberately receives nothing here.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'manage_withdrawals', 'guard_name' => 'web']);

        Tenant::query()->active()->each(function (Tenant $tenant) {
            app(TenantContext::class)->run($tenant, function () use ($tenant) {
                $admin = Role::firstOrCreate([
                    'team_id' => $tenant->id, 'name' => 'admin', 'guard_name' => 'web',
                ]);
                $admin->givePermissionTo('manage_withdrawals');
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage_withdrawals')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
