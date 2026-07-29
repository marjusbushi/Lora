<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class TenantIntegrityAuditor
{
    /** @var list<array{0:string,1:string,2:string}> */
    private const RELATIONS = [
        ['bill_items', 'bill_id', 'bills'],
        ['bill_items', 'inventory_item_id', 'inventory_items'],
        ['bill_items', 'warehouse_id', 'warehouses'],
        ['billing_invoices', 'tenant_subscription_id', 'tenant_subscriptions'],
        ['billing_payment_attempts', 'billing_invoice_id', 'billing_invoices'],
        ['billing_payment_attempts', 'billing_payment_id', 'billing_payments'],
        ['billing_payment_attempts', 'tenant_subscription_id', 'tenant_subscriptions'],
        ['billing_payments', 'billing_invoice_id', 'billing_invoices'],
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
        ['guest_merges', 'primary_guest_id', 'guests'],
        ['guest_merges', 'secondary_guest_id', 'guests'],
        ['guests', 'merged_into_guest_id', 'guests'],
        ['inventory_items', 'room_warehouse_id', 'warehouses'],
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
        ['menu_items', 'inventory_item_id', 'inventory_items'],
        ['menu_items', 'warehouse_id', 'warehouses'],
        ['message_threads', 'reservation_id', 'reservations'],
        ['messages', 'message_thread_id', 'message_threads'],
        ['payments', 'reservation_id', 'reservations'],
        ['pos_order_items', 'menu_item_id', 'menu_items'],
        ['pos_order_items', 'pos_order_id', 'pos_orders'],
        ['pos_order_items', 'pos_order_round_id', 'pos_order_rounds'],
        ['pos_order_payments', 'pos_order_id', 'pos_orders'],
        ['pos_order_payments', 'pos_shift_id', 'pos_shifts'],
        ['pos_order_payments', 'refunded_from_id', 'pos_order_payments'],
        ['pos_order_rounds', 'pos_order_id', 'pos_orders'],
        ['pos_orders', 'pos_table_id', 'pos_tables'],
        ['pos_orders', 'pos_shift_id', 'pos_shifts'],
        ['pos_orders', 'reservation_id', 'reservations'],
        ['pricing_autopilot_logs', 'room_type_id', 'room_types'],
        ['pricing_manual_protections', 'room_type_id', 'room_types'],
        ['provider_events', 'billing_invoice_id', 'billing_invoices'],
        ['provider_events', 'billing_payment_attempt_id', 'billing_payment_attempts'],
        ['provider_events', 'billing_payment_id', 'billing_payments'],
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

    /** @var list<string> */
    private const NULLABLE_TENANT_TABLES = [
        'provider_events',
    ];

    /** @return list<string> */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->tenantTables() as $table) {
            $tenantRows = DB::table("{$table} as child")
                ->leftJoin('tenants as tenant', 'tenant.id', '=', 'child.tenant_id');

            if (in_array($table, self::NULLABLE_TENANT_TABLES, true)) {
                $invalidTenantCount = $tenantRows
                    ->whereNotNull('child.tenant_id')
                    ->whereNull('tenant.id')
                    ->count();
            } else {
                $invalidTenantCount = $tenantRows
                    ->where(fn ($query) => $query
                        ->whereNull('child.tenant_id')
                        ->orWhereNull('tenant.id'))
                    ->count();
            }

            if ($invalidTenantCount > 0) {
                $description = in_array($table, self::NULLABLE_TENANT_TABLES, true)
                    ? 'an unknown tenant_id'
                    : 'a null or unknown tenant_id';
                $violations[] = "{$table}: {$invalidTenantCount} rows have {$description}";
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

            if (in_array($childTable, self::NULLABLE_TENANT_TABLES, true)) {
                $missingTenant = (clone $relation)
                    ->whereNotNull('parent.id')
                    ->whereNull('child.tenant_id')
                    ->count();
                if ($missingTenant > 0) {
                    $violations[] = "{$childTable}.{$column}: {$missingTenant} linked rows have a null tenant_id";
                }
            }

            $crossTenant = (clone $relation)
                ->whereNotNull('parent.id')
                ->whereNotNull('child.tenant_id')
                ->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')
                ->count();
            if ($crossTenant > 0) {
                $violations[] = "{$childTable}.{$column}: {$crossTenant} rows cross tenant boundaries";
            }
        }

        if (Schema::hasTable('guests')
            && Schema::hasColumn('guests', 'merged_into_guest_id')
            && Schema::hasColumn('guests', 'merged_into_guest_tenant_id')) {
            $invalidGuestShadows = DB::table('guests')
                ->where(function ($query) {
                    $query->whereNull('merged_into_guest_id')
                        ->whereNotNull('merged_into_guest_tenant_id');
                })
                ->orWhere(function ($query) {
                    $query->whereNotNull('merged_into_guest_id')
                        ->where(function ($nested) {
                            $nested->whereNull('merged_into_guest_tenant_id')
                                ->orWhereColumn('merged_into_guest_tenant_id', '!=', 'tenant_id');
                        });
                })
                ->count();

            if ($invalidGuestShadows > 0) {
                $violations[] = "guests.merged_into_guest_tenant_id: {$invalidGuestShadows} rows have an inconsistent tenant shadow key";
            }
        }

        if (Schema::hasTable('pos_order_payments')
            && Schema::hasColumn('pos_order_payments', 'refunded_from_id')
            && Schema::hasColumn('pos_order_payments', 'refunded_from_tenant_id')) {
            $invalidRefundShadows = DB::table('pos_order_payments')
                ->where(function ($query) {
                    $query->whereNull('refunded_from_id')
                        ->whereNotNull('refunded_from_tenant_id');
                })
                ->orWhere(function ($query) {
                    $query->whereNotNull('refunded_from_id')
                        ->where(function ($nested) {
                            $nested->whereNull('refunded_from_tenant_id')
                                ->orWhereColumn('refunded_from_tenant_id', '!=', 'tenant_id');
                        });
                })
                ->count();

            if ($invalidRefundShadows > 0) {
                $violations[] = "pos_order_payments.refunded_from_tenant_id: {$invalidRefundShadows} rows have an inconsistent tenant shadow key";
            }
        }

        if ($this->invitationRoleRelationExists()) {
            $invalidInvitationRoles = DB::table('tenant_user_invitations as invitation')
                ->leftJoin('roles as role', 'role.id', '=', 'invitation.role_id')
                ->where(fn ($query) => $query
                    ->whereNull('role.id')
                    ->orWhereNull('role.team_id')
                    ->orWhereColumn('role.team_id', '!=', 'invitation.tenant_id'))
                ->count();

            if ($invalidInvitationRoles > 0) {
                $violations[] = "tenant_user_invitations.role_id: {$invalidInvitationRoles} rows reference a missing or cross-tenant role";
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

    /** @return list<string> */
    public function storageViolations(): array
    {
        $violations = [];
        $references = [
            ['guest_documents', 'path', 'local', null, null, 'tenant'],
            ['maintenance_attachments', 'path', null, 'disk', null, 'tenant'],
            ['tenant_onboarding_documents', 'path', null, 'disk', null, 'onboarding'],
            ['room_type_images', 'path', 'public', null, null, 'tenant'],
            ['menu_items', 'image_path', 'public', null, null, 'tenant'],
            ['inventory_items', 'image_path', 'public', null, null, 'tenant'],
            ['settings', 'value', 'public', null, ['type', 'image'], 'tenant'],
        ];

        foreach ($references as [$table, $pathColumn, $fixedDisk, $diskColumn, $filter, $namespace]) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'id')
                || ! Schema::hasColumn($table, $pathColumn)
                || ($diskColumn && ! Schema::hasColumn($table, $diskColumn))) {
                continue;
            }

            $unsafe = 0;
            $missing = 0;
            $crossTenant = 0;
            $columns = ["storage_ref.id as id", "storage_ref.{$pathColumn} as storage_path"];
            if ($diskColumn) {
                $columns[] = "storage_ref.{$diskColumn} as storage_disk";
            }

            $query = DB::table("{$table} as storage_ref")
                ->select($columns)
                ->whereNotNull("storage_ref.{$pathColumn}");
            $tenantIdAvailable = false;
            if (Schema::hasColumn($table, 'tenant_id')) {
                $query->addSelect('storage_ref.tenant_id as storage_tenant_id');
                $tenantIdAvailable = true;
            } elseif ($table === 'tenant_onboarding_documents'
                && Schema::hasTable('tenant_onboardings')
                && Schema::hasColumn('tenant_onboardings', 'tenant_id')
                && Schema::hasColumn($table, 'tenant_onboarding_id')) {
                $query->leftJoin(
                    'tenant_onboardings as storage_owner',
                    'storage_owner.id',
                    '=',
                    'storage_ref.tenant_onboarding_id',
                )->addSelect('storage_owner.tenant_id as storage_tenant_id');
                $tenantIdAvailable = true;
            }
            if ($filter) {
                $query->where("storage_ref.{$filter[0]}", $filter[1]);
            }

            $query->chunkById(500, function ($rows) use (
                &$unsafe,
                &$missing,
                &$crossTenant,
                $fixedDisk,
                $diskColumn,
                $tenantIdAvailable,
                $namespace,
            ) {
                foreach ($rows as $row) {
                    $path = (string) $row->storage_path;
                    $disk = $fixedDisk ?? (string) $row->storage_disk;

                    if (! in_array($disk, ['local', 'public'], true)
                        || ! $this->isSafeStoragePath($path)) {
                        $unsafe++;

                        continue;
                    }

                    $tenantId = $tenantIdAvailable ? $row->storage_tenant_id : null;
                    if (! $this->storagePathBelongsToTenant($path, $tenantId, $namespace)) {
                        $crossTenant++;

                        continue;
                    }

                    try {
                        if (! Storage::disk($disk)->exists($path)) {
                            $missing++;
                        }
                    } catch (Throwable) {
                        $missing++;
                    }
                }
            }, 'storage_ref.id', 'id');

            if ($unsafe > 0) {
                $violations[] = "{$table}.{$pathColumn}: {$unsafe} rows have an unsafe storage disk or path";
            }
            if ($missing > 0) {
                $violations[] = "{$table}.{$pathColumn}: {$missing} rows reference a missing stored file";
            }
            if ($crossTenant > 0) {
                $violations[] = "{$table}.{$pathColumn}: {$crossTenant} rows reference another tenant's storage namespace";
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

    private function invitationRoleRelationExists(): bool
    {
        return Schema::hasTable('tenant_user_invitations')
            && Schema::hasTable('roles')
            && Schema::hasColumn('tenant_user_invitations', 'tenant_id')
            && Schema::hasColumn('tenant_user_invitations', 'role_id')
            && Schema::hasColumn('roles', 'team_id');
    }

    private function isSafeStoragePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! str_contains($path, '\\')
            && preg_match('#(?:^|/)[.][.](?:/|$)#', $path) !== 1;
    }

    private function storagePathBelongsToTenant(string $path, mixed $tenantId, string $namespace): bool
    {
        $tenant = (string) $tenantId;
        if ($tenant === '' || preg_match('/^[1-9][0-9]*$/', $tenant) !== 1) {
            return $namespace === 'tenant' && ! str_starts_with($path, 'tenants/');
        }

        if ($namespace === 'onboarding') {
            return preg_match('#^onboarding/tenant-([1-9][0-9]*)/.+$#', $path, $matches) === 1
                && hash_equals($tenant, $matches[1]);
        }

        if (! str_starts_with($path, 'tenants/')) {
            return true;
        }

        return preg_match('#^tenants/([1-9][0-9]*)/.+$#', $path, $matches) === 1
            && hash_equals($tenant, $matches[1]);
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
