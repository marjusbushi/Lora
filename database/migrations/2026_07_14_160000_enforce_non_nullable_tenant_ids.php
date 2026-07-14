<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tenantTables = [
        'amenities',
        'audit_logs',
        'bill_items',
        'bills',
        'budgets',
        'channel_mappings',
        'channel_sync_logs',
        'cleaning_tasks',
        'comp_rates',
        'expenses',
        'finance_accounts',
        'finance_payments',
        'floors',
        'folio_items',
        'guest_documents',
        'guests',
        'inventory_items',
        'inventory_movements',
        'inventory_transfers',
        'invoices',
        'maintenance_attachments',
        'maintenance_issue_events',
        'maintenance_issues',
        'marketing_spends',
        'menu_categories',
        'menu_item_inventory',
        'menu_items',
        'message_threads',
        'messages',
        'payments',
        'pos_order_items',
        'pos_orders',
        'pos_shifts',
        'pricing_autopilot_logs',
        'pricing_events',
        'pricing_manual_protections',
        'pricing_reports',
        'rate_overrides',
        'reservation_status_logs',
        'reservations',
        'reviews',
        'room_inventory_snapshots',
        'room_type_images',
        'room_types',
        'rooms',
        'season_rates',
        'seasons',
        'settings',
        'suppliers',
        'tenant_domains',
        'tenant_integrations',
        'tenant_module_entitlements',
        'tenant_subscriptions',
        'tenant_user',
        'warehouses',
        'website_search_logs',
    ];

    /** @var list<string> */
    private array $nullableTenantTables = [
        'bill_items',
        'bills',
        'comp_rates',
        'finance_accounts',
        'finance_payments',
        'inventory_items',
        'inventory_movements',
        'inventory_transfers',
        'invoices',
        'menu_item_inventory',
        'suppliers',
        'warehouses',
    ];

    public function up(): void
    {
        $this->assertTenantIdsAreValid();
        $this->assertMessageReservationsExist();

        foreach ($this->nullableTenantTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            });
        }

        Schema::table('message_threads', function (Blueprint $table) {
            $table->foreign('tenant_id')
                ->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('reservation_id')
                ->references('id')->on('reservations')->nullOnDelete();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreign('tenant_id')
                ->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
        });

        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropForeign(['reservation_id']);
            $table->dropForeign(['tenant_id']);
        });

        foreach ($this->nullableTenantTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->change();
            });
        }
    }

    private function assertTenantIdsAreValid(): void
    {
        foreach ($this->tenantTables as $tableName) {
            $invalid = DB::table("{$tableName} as child")
                ->leftJoin('tenants as tenant', 'tenant.id', '=', 'child.tenant_id')
                ->where(function ($query) {
                    $query->whereNull('child.tenant_id')->orWhereNull('tenant.id');
                })
                ->exists();

            if ($invalid) {
                throw new RuntimeException(
                    "Tenant integrity failed for {$tableName}: tenant_id is null or references a missing tenant.",
                );
            }
        }
    }

    private function assertMessageReservationsExist(): void
    {
        $hasOrphan = DB::table('message_threads as child')
            ->leftJoin('reservations as parent', 'parent.id', '=', 'child.reservation_id')
            ->whereNotNull('child.reservation_id')
            ->whereNull('parent.id')
            ->exists();

        if ($hasOrphan) {
            throw new RuntimeException('Tenant integrity failed: message_threads contains an unknown reservation_id.');
        }
    }
};
