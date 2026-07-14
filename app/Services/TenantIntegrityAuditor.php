<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TenantIntegrityAuditor
{
    /** @var list<array{0:string,1:string,2:string}> */
    private const RELATIONS = [
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

    /** @return list<string> */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->tenantTables() as $table) {
            $invalidTenantCount = DB::table("{$table} as child")
                ->leftJoin('tenants as tenant', 'tenant.id', '=', 'child.tenant_id')
                ->where(fn ($query) => $query
                    ->whereNull('child.tenant_id')
                    ->orWhereNull('tenant.id'))
                ->count();

            if ($invalidTenantCount > 0) {
                $violations[] = "{$table}: {$invalidTenantCount} rows have a null or unknown tenant_id";
            }
        }

        foreach (self::RELATIONS as [$childTable, $column, $parentTable]) {
            if (! $this->relationExists($childTable, $column, $parentTable)) {
                continue;
            }

            $relation = DB::table("{$childTable} as child")
                ->leftJoin("{$parentTable} as parent", 'parent.id', '=', "child.{$column}")
                ->whereNotNull("child.{$column}");

            $unknown = (clone $relation)->whereNull('parent.id')->count();
            if ($unknown > 0) {
                $violations[] = "{$childTable}.{$column}: {$unknown} rows reference a missing {$parentTable} row";
            }

            $crossTenant = (clone $relation)
                ->whereNotNull('parent.id')
                ->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')
                ->count();
            if ($crossTenant > 0) {
                $violations[] = "{$childTable}.{$column}: {$crossTenant} rows cross tenant boundaries";
            }
        }

        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'team_id')) {
                $missingTeam = DB::table($table)->whereNull('team_id')->count();
                if ($missingTeam > 0) {
                    $violations[] = "{$table}: {$missingTeam} rows have a null team_id";
                }
            }
        }

        return $violations;
    }

    /**
     * PII-free baseline: record counts per tenant and financial totals only.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $tenantCounts = [];

        foreach ($this->tenantTables() as $table) {
            $counts = DB::table($table)
                ->selectRaw('tenant_id, COUNT(*) as aggregate')
                ->groupBy('tenant_id')
                ->orderBy('tenant_id')
                ->get()
                ->mapWithKeys(fn ($row) => [(string) $row->tenant_id => (int) $row->aggregate])
                ->all();

            $tenantCounts[$table] = $counts;
        }

        $snapshot = [
            'central_counts' => $this->centralCounts(),
            'tenant_counts' => $tenantCounts,
            'financial_totals' => [
                'bills' => $this->moneyTotals('bills', 'total_base'),
                'finance_payments' => $this->moneyTotals('finance_payments', 'amount_base', 'direction'),
                'invoices' => $this->moneyTotals('invoices', 'total'),
                'payments' => $this->moneyTotals('payments', 'amount'),
                'pos_orders' => $this->moneyTotals('pos_orders', 'total_amount'),
                'reservations' => $this->moneyTotals('reservations', 'total_amount'),
            ],
        ];

        $this->sortRecursively($snapshot);

        return $snapshot;
    }

    /** @return array<string, int> */
    private function centralCounts(): array
    {
        $counts = [];

        foreach (['tenants', 'users', 'roles', 'permissions'] as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    /** @return array<string, string> */
    private function moneyTotals(string $table, string $amountColumn, ?string $dimension = null): array
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'tenant_id')
            || ! Schema::hasColumn($table, $amountColumn)) {
            return [];
        }

        $groupColumns = array_values(array_filter(['tenant_id', $dimension]));
        $rows = DB::table($table)
            ->select($groupColumns)
            ->selectRaw("SUM({$amountColumn}) as aggregate")
            ->groupBy($groupColumns)
            ->orderBy('tenant_id')
            ->when($dimension, fn ($query) => $query->orderBy($dimension))
            ->get();

        return $rows->mapWithKeys(function ($row) use ($dimension) {
            $key = (string) $row->tenant_id;
            if ($dimension) {
                $key .= ':'.(string) $row->{$dimension};
            }

            return [$key => number_format((float) $row->aggregate, 2, '.', '')];
        })->all();
    }

    /** @return list<string> */
    private function tenantTables(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn ($table) => is_string($table) && Schema::hasColumn($table, 'tenant_id'))
            ->sort()
            ->values()
            ->all();
    }

    private function relationExists(string $childTable, string $column, string $parentTable): bool
    {
        return Schema::hasTable($childTable)
            && Schema::hasTable($parentTable)
            && Schema::hasColumn($childTable, 'tenant_id')
            && Schema::hasColumn($childTable, $column)
            && Schema::hasColumn($parentTable, 'tenant_id');
    }

    private function sortRecursively(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
    }
}
