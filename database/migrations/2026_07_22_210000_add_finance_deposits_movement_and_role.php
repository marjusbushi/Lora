<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Capital movements (owner deposits/withdrawals) on Arka & Banka: a nullable
 * `movement` tag on the existing ledger (balances keep flowing untouched), a
 * new manage_deposits permission, and a dedicated `finance` role provisioned
 * for every existing tenant. Mirrors 2026_07_14_150100_add_maintenance_permissions.
 */
return new class extends Migration
{
    /** Frozen-in-time copy of the finance role's grants at this migration's date. */
    private const FINANCE_ROLE_PERMISSIONS = [
        'view_finance', 'view_bank_accounts', 'create_payment', 'pay_bills',
        'manage_transfers', 'manage_invoices', 'manage_bills',
        'manage_suppliers', 'manage_deposits',
        'view_reports',
    ];

    public function up(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            // Nullable on purpose: NULL = ordinary operational payment; only
            // capital movements carry 'deposit' / 'withdrawal'. Report filters
            // on (tenant_id, movement).
            $table->string('movement', 16)->nullable()->after('source');
            $table->index(['tenant_id', 'movement']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // A fresh database has only migration-created permissions — every
        // grant below must exist before it is handed to a role.
        foreach (self::FINANCE_ROLE_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Tenant::query()->active()->each(function (Tenant $tenant) {
            app(TenantContext::class)->run($tenant, function () use ($tenant) {
                // Admin holds '*' — hand it the new permission explicitly.
                $admin = Role::firstOrCreate([
                    'team_id' => $tenant->id, 'name' => 'admin', 'guard_name' => 'web',
                ]);
                $admin->givePermissionTo('manage_deposits');

                $finance = Role::firstOrCreate([
                    'team_id' => $tenant->id, 'name' => 'finance', 'guard_name' => 'web',
                ]);
                $finance->givePermissionTo(self::FINANCE_ROLE_PERMISSIONS);
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'movement']);
            $table->dropColumn('movement');
        });

        Role::where('name', 'finance')->where('guard_name', 'web')->delete();
        Permission::where('name', 'manage_deposits')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
