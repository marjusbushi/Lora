<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $parentTables = [
        'bills',
        'cleaning_tasks',
        'finance_accounts',
        'guests',
        'inventory_items',
        'invoices',
        'maintenance_issues',
        'menu_categories',
        'menu_items',
        'message_threads',
        'pos_orders',
        'pos_shifts',
        'reservations',
        'rooms',
        'room_types',
        'seasons',
        'suppliers',
        'warehouses',
    ];

    /** @var list<array{0:string,1:string,2:string}> */
    private array $relations = [
        ['bill_items', 'bill_id', 'bills'],
        ['bill_items', 'inventory_item_id', 'inventory_items'],
        ['bill_items', 'warehouse_id', 'warehouses'],
        ['bills', 'supplier_id', 'suppliers'],
        ['channel_mappings', 'room_type_id', 'room_types'],
        ['channel_sync_logs', 'reservation_id', 'reservations'],
        ['channel_sync_logs', 'room_type_id', 'room_types'],
        ['cleaning_tasks', 'room_id', 'rooms'],
        ['finance_payments', 'account_id', 'finance_accounts'],
        ['finance_payments', 'bill_id', 'bills'],
        ['finance_payments', 'counter_account_id', 'finance_accounts'],
        ['finance_payments', 'invoice_id', 'invoices'],
        ['folio_items', 'inventory_item_id', 'inventory_items'],
        ['folio_items', 'pos_order_id', 'pos_orders'],
        ['folio_items', 'reservation_id', 'reservations'],
        ['folio_items', 'warehouse_id', 'warehouses'],
        ['guest_documents', 'guest_id', 'guests'],
        ['inventory_movements', 'inventory_item_id', 'inventory_items'],
        ['inventory_movements', 'warehouse_id', 'warehouses'],
        ['inventory_transfers', 'from_warehouse_id', 'warehouses'],
        ['inventory_transfers', 'inventory_item_id', 'inventory_items'],
        ['inventory_transfers', 'to_warehouse_id', 'warehouses'],
        ['invoices', 'guest_id', 'guests'],
        ['invoices', 'reservation_id', 'reservations'],
        ['maintenance_attachments', 'maintenance_issue_id', 'maintenance_issues'],
        ['maintenance_issue_events', 'maintenance_issue_id', 'maintenance_issues'],
        ['maintenance_issues', 'cleaning_task_id', 'cleaning_tasks'],
        ['maintenance_issues', 'room_id', 'rooms'],
        ['menu_categories', 'warehouse_id', 'warehouses'],
        ['menu_item_inventory', 'inventory_item_id', 'inventory_items'],
        ['menu_item_inventory', 'menu_item_id', 'menu_items'],
        ['menu_items', 'menu_category_id', 'menu_categories'],
        ['message_threads', 'reservation_id', 'reservations'],
        ['messages', 'message_thread_id', 'message_threads'],
        ['payments', 'reservation_id', 'reservations'],
        ['pos_order_items', 'menu_item_id', 'menu_items'],
        ['pos_order_items', 'pos_order_id', 'pos_orders'],
        ['pos_orders', 'pos_shift_id', 'pos_shifts'],
        ['pos_orders', 'reservation_id', 'reservations'],
        ['pricing_autopilot_logs', 'room_type_id', 'room_types'],
        ['pricing_manual_protections', 'room_type_id', 'room_types'],
        ['rate_overrides', 'room_type_id', 'room_types'],
        ['reservation_status_logs', 'reservation_id', 'reservations'],
        ['reservations', 'guest_id', 'guests'],
        ['reservations', 'room_id', 'rooms'],
        ['reviews', 'guest_id', 'guests'],
        ['reviews', 'reservation_id', 'reservations'],
        ['room_inventory_snapshots', 'room_type_id', 'room_types'],
        ['room_type_images', 'room_type_id', 'room_types'],
        ['rooms', 'room_type_id', 'room_types'],
        ['season_rates', 'room_type_id', 'room_types'],
        ['season_rates', 'season_id', 'seasons'],
        ['website_search_logs', 'room_type_id', 'room_types'],
    ];

    /**
     * MySQL creates these supporting indexes implicitly when the composite
     * foreign keys are added. It does not remove them with the foreign keys,
     * so rollback must remove exactly the indexes introduced by this migration.
     *
     * @var list<array{0:string,1:string}>
     */
    private array $mysqlImplicitIndexes = [
        ['bill_items', 'warehouse_id'],
        ['bills', 'supplier_id'],
        ['channel_mappings', 'room_type_id'],
        ['channel_sync_logs', 'reservation_id'],
        ['channel_sync_logs', 'room_type_id'],
        ['cleaning_tasks', 'room_id'],
        ['finance_payments', 'bill_id'],
        ['finance_payments', 'counter_account_id'],
        ['finance_payments', 'invoice_id'],
        ['folio_items', 'inventory_item_id'],
        ['folio_items', 'pos_order_id'],
        ['folio_items', 'reservation_id'],
        ['folio_items', 'warehouse_id'],
        ['guest_documents', 'guest_id'],
        ['inventory_movements', 'warehouse_id'],
        ['inventory_transfers', 'from_warehouse_id'],
        ['inventory_transfers', 'inventory_item_id'],
        ['inventory_transfers', 'to_warehouse_id'],
        ['invoices', 'guest_id'],
        ['invoices', 'reservation_id'],
        ['maintenance_issues', 'cleaning_task_id'],
        ['maintenance_issues', 'room_id'],
        ['menu_categories', 'warehouse_id'],
        ['menu_item_inventory', 'inventory_item_id'],
        ['menu_items', 'menu_category_id'],
        ['message_threads', 'reservation_id'],
        ['payments', 'reservation_id'],
        ['pos_order_items', 'menu_item_id'],
        ['pos_order_items', 'pos_order_id'],
        ['pos_orders', 'pos_shift_id'],
        ['pos_orders', 'reservation_id'],
        ['pricing_autopilot_logs', 'room_type_id'],
        ['pricing_manual_protections', 'room_type_id'],
        ['rate_overrides', 'room_type_id'],
        ['reservation_status_logs', 'reservation_id'],
        ['reservations', 'guest_id'],
        ['reservations', 'room_id'],
        ['reviews', 'guest_id'],
        ['reviews', 'reservation_id'],
        ['room_inventory_snapshots', 'room_type_id'],
        ['room_type_images', 'room_type_id'],
        ['rooms', 'room_type_id'],
        ['season_rates', 'room_type_id'],
        ['season_rates', 'season_id'],
        ['website_search_logs', 'room_type_id'],
    ];

    public function up(): void
    {
        $this->assertRelationsAreValid();

        foreach ($this->parentTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unique(['tenant_id', 'id']);
            });
        }

        foreach ($this->relationsByChild() as $childTable => $relations) {
            Schema::table($childTable, function (Blueprint $table) use ($relations) {
                foreach ($relations as [, $column, $parentTable]) {
                    $table->foreign(['tenant_id', $column])
                        ->references(['tenant_id', 'id'])->on($parentTable);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->relationsByChild(), true) as $childTable => $relations) {
            Schema::table($childTable, function (Blueprint $table) use ($relations) {
                foreach (array_reverse($relations) as [, $column]) {
                    $table->dropForeign(['tenant_id', $column]);
                }
            });
        }

        if (DB::getDriverName() === 'mysql') {
            foreach ($this->mysqlImplicitIndexes as [$tableName, $column]) {
                $indexName = "{$tableName}_tenant_id_{$column}_foreign";

                if ($this->hasIndex($tableName, $indexName)) {
                    $this->ensureTenantIndexSurvives($tableName, $indexName);

                    Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                        $table->dropIndex($indexName);
                    });
                }
            }
        }

        foreach (array_reverse($this->parentTables) as $tableName) {
            // MySQL may remove the old single-column tenant_id index as
            // redundant when the (tenant_id, id) unique index is created.
            // The table's own tenant_id foreign key still needs an index, so
            // restore one before dropping the composite unique on rollback.
            if (DB::getDriverName() === 'mysql') {
                $uniqueName = "{$tableName}_tenant_id_id_unique";
                $hasTenantIndex = collect(Schema::getIndexes($tableName))
                    ->contains(fn (array $index) => $index['name'] !== $uniqueName
                        && ($index['columns'][0] ?? null) === 'tenant_id');

                if (! $hasTenantIndex) {
                    $indexName = $tableName === 'message_threads'
                        ? 'message_threads_tenant_id_index'
                        : "{$tableName}_tenant_id_foreign";

                    Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                        $table->index('tenant_id', $indexName);
                    });
                }
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropUnique(['tenant_id', 'id']);
            });
        }
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        return collect(Schema::getIndexes($tableName))
            ->contains(fn (array $index) => $index['name'] === $indexName);
    }

    private function ensureTenantIndexSurvives(string $tableName, string $droppingIndex): void
    {
        $parentUnique = "{$tableName}_tenant_id_id_unique";
        $hasSurvivingIndex = collect(Schema::getIndexes($tableName))
            ->contains(fn (array $index) => $index['name'] !== $droppingIndex
                && $index['name'] !== $parentUnique
                && ($index['columns'][0] ?? null) === 'tenant_id');

        if ($hasSurvivingIndex) {
            return;
        }

        // InnoDB may remove the original single-column index as redundant
        // after the composite relation is added. Recreate it with its
        // pre-migration name before removing the composite index.
        $indexName = $tableName === 'message_threads'
            ? 'message_threads_tenant_id_index'
            : "{$tableName}_tenant_id_foreign";

        if (! $this->hasIndex($tableName, $indexName)) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->index('tenant_id', $indexName);
            });
        }
    }

    private function assertRelationsAreValid(): void
    {
        foreach ($this->relations as [$childTable, $column, $parentTable]) {
            $query = DB::table("{$childTable} as child")
                ->leftJoin("{$parentTable} as parent", 'parent.id', '=', "child.{$column}")
                ->whereNotNull("child.{$column}");

            if ((clone $query)->whereNull('parent.id')->exists()) {
                throw new RuntimeException(
                    "Tenant integrity failed: {$childTable}.{$column} contains an unknown {$parentTable} id.",
                );
            }

            if ((clone $query)->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')->exists()) {
                throw new RuntimeException(
                    "Tenant integrity failed: {$childTable}.{$column} crosses tenant boundaries.",
                );
            }
        }
    }

    /** @return array<string, list<array{0:string,1:string,2:string}>> */
    private function relationsByChild(): array
    {
        $grouped = [];

        foreach ($this->relations as $relation) {
            $grouped[$relation[0]][] = $relation;
        }

        return $grouped;
    }
};
