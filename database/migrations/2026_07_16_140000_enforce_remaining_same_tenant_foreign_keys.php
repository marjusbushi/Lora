<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GUEST_LINK_TENANT_COLUMN = 'merged_into_guest_tenant_id';

    private const GUEST_LINK_FOREIGN = 'guests_merged_into_tenant_fk';

    private const GUEST_LINK_INDEX = 'guests_merged_target_tenant_idx';

    /** @var list<array{0:string,1:string,2:string,3:string}> */
    private array $restrictRelations = [
        ['guest_merges', 'primary_guest_id', 'guests', 'guest_merges_primary_tenant_fk'],
        ['guest_merges', 'secondary_guest_id', 'guests', 'guest_merges_secondary_tenant_fk'],
    ];

    /**
     * These nullable relations already have single-column ON DELETE SET NULL
     * foreign keys. Triggers enforce tenant equality without changing that
     * deletion behavior.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private array $triggerRelations = [
        ['inventory_items', 'room_warehouse_id', 'warehouses'],
        ['menu_items', 'inventory_item_id', 'inventory_items'],
        ['menu_items', 'warehouse_id', 'warehouses'],
        ['billing_invoices', 'tenant_subscription_id', 'tenant_subscriptions'],
        ['billing_payments', 'billing_invoice_id', 'billing_invoices'],
        ['billing_payment_attempts', 'tenant_subscription_id', 'tenant_subscriptions'],
        ['billing_payment_attempts', 'billing_invoice_id', 'billing_invoices'],
        ['billing_payment_attempts', 'billing_payment_id', 'billing_payments'],
        ['provider_events', 'billing_payment_attempt_id', 'billing_payment_attempts'],
        ['provider_events', 'billing_invoice_id', 'billing_invoices'],
        ['provider_events', 'billing_payment_id', 'billing_payments'],
    ];

    public function up(): void
    {
        $this->assertSupportedDriver();
        $this->assertRelationsAreValid();

        $this->replaceGuestMergeForeignWithTenantAwareSetNullForeign();
        $this->addRestrictSameTenantForeignKeys();
        $this->addSameTenantValidationTriggers();
        $this->addSameTenantParentUpdateGuards();
        $this->addTenantDeleteProviderCleanupTrigger();
    }

    public function down(): void
    {
        $this->dropTenantDeleteProviderCleanupTrigger();
        $this->dropSameTenantParentUpdateGuards();
        $this->dropSameTenantValidationTriggers();
        $this->dropGuestTenantSyncTriggers();
        $this->dropRestrictSameTenantForeignKeys();
        $this->restoreGuestMergeSimpleForeign();
    }

    /**
     * MySQL row triggers cannot safely query the table that fired them. A
     * synced nullable shadow key therefore lets the self-reference enforce
     * (tenant_id, id) while SET NULL clears only link-specific columns.
     */
    private function replaceGuestMergeForeignWithTenantAwareSetNullForeign(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->unsignedBigInteger(self::GUEST_LINK_TENANT_COLUMN)
                ->nullable()
                ->after('merged_into_guest_id');
            $table->index(
                [self::GUEST_LINK_TENANT_COLUMN, 'merged_into_guest_id'],
                self::GUEST_LINK_INDEX,
            );
        });

        DB::table('guests')
            ->whereNotNull('merged_into_guest_id')
            ->update([self::GUEST_LINK_TENANT_COLUMN => DB::raw('tenant_id')]);

        $dropForeignByColumns = DB::getDriverName() === 'sqlite';
        Schema::table('guests', function (Blueprint $table) use ($dropForeignByColumns) {
            $table->dropForeign($dropForeignByColumns
                ? ['merged_into_guest_id']
                : 'guests_merged_into_guest_id_foreign');
            $table->foreign(
                [self::GUEST_LINK_TENANT_COLUMN, 'merged_into_guest_id'],
                self::GUEST_LINK_FOREIGN,
            )
                ->references(['tenant_id', 'id'])
                ->on('guests')
                ->nullOnDelete();
        });

        $this->addGuestTenantSyncTriggers();
        $this->repairAndAssertGuestShadowKey();
    }

    private function restoreGuestMergeSimpleForeign(): void
    {
        $dropForeignByColumns = DB::getDriverName() === 'sqlite';
        Schema::table('guests', function (Blueprint $table) use ($dropForeignByColumns) {
            $table->dropForeign($dropForeignByColumns
                ? [self::GUEST_LINK_TENANT_COLUMN, 'merged_into_guest_id']
                : self::GUEST_LINK_FOREIGN);
            $table->foreign('merged_into_guest_id')
                ->references('id')
                ->on('guests')
                ->nullOnDelete();
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex(self::GUEST_LINK_INDEX);
            $table->dropColumn(self::GUEST_LINK_TENANT_COLUMN);
        });
    }

    private function addRestrictSameTenantForeignKeys(): void
    {
        Schema::table('guest_merges', function (Blueprint $table) {
            foreach ($this->restrictRelations as [, $column, $parentTable, $constraintName]) {
                $table->foreign(['tenant_id', $column], $constraintName)
                    ->references(['tenant_id', 'id'])
                    ->on($parentTable)
                    ->restrictOnDelete();
            }
        });
    }

    private function dropRestrictSameTenantForeignKeys(): void
    {
        $dropForeignByColumns = DB::getDriverName() === 'sqlite';
        Schema::table('guest_merges', function (Blueprint $table) use ($dropForeignByColumns) {
            foreach (array_reverse($this->restrictRelations) as [, $column, , $constraintName]) {
                $table->dropForeign($dropForeignByColumns
                    ? ['tenant_id', $column]
                    : $constraintName);
            }
        });
    }

    private function addGuestTenantSyncTriggers(): void
    {
        foreach (['INSERT', 'UPDATE'] as $operation) {
            $triggerName = $this->guestSyncTriggerName($operation);

            if ($this->isMySqlFamily()) {
                DB::unprepared(<<<SQL
CREATE TRIGGER {$triggerName}
BEFORE {$operation} ON guests
FOR EACH ROW
SET NEW.merged_into_guest_tenant_id = CASE
    WHEN NEW.merged_into_guest_id IS NULL THEN NULL
    ELSE NEW.tenant_id
END
SQL);

                continue;
            }

            $eventColumns = $operation === 'UPDATE'
                ? ' OF tenant_id, merged_into_guest_id, merged_into_guest_tenant_id'
                : '';
            DB::unprepared(<<<SQL
CREATE TRIGGER {$triggerName}
AFTER {$operation}{$eventColumns} ON guests
FOR EACH ROW
WHEN NEW.merged_into_guest_tenant_id IS NOT (
    CASE WHEN NEW.merged_into_guest_id IS NULL THEN NULL ELSE NEW.tenant_id END
)
BEGIN
    UPDATE guests
    SET merged_into_guest_tenant_id = CASE
        WHEN NEW.merged_into_guest_id IS NULL THEN NULL
        ELSE NEW.tenant_id
    END
    WHERE id = NEW.id;
END
SQL);
        }
    }

    private function dropGuestTenantSyncTriggers(): void
    {
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->guestSyncTriggerName($operation));
        }
    }

    private function addSameTenantValidationTriggers(): void
    {
        $driver = DB::getDriverName();

        foreach ($this->triggerRelationsByChild() as $childTable => $relations) {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $triggerName = $this->validationTriggerName($childTable, $operation);
                $statements = $this->isMySqlFamily()
                    ? $this->mysqlValidationStatements($childTable, $relations, $driver, $operation)
                    : $this->sqliteValidationStatements($childTable, $relations, $operation);
                $eventColumns = ! $this->isMySqlFamily() && $operation === 'UPDATE'
                    ? ' OF '.collect($relations)->pluck(1)->prepend('tenant_id')->unique()->implode(', ')
                    : '';

                DB::unprepared(<<<SQL
CREATE TRIGGER {$triggerName}
BEFORE {$operation}{$eventColumns} ON {$childTable}
FOR EACH ROW
BEGIN
{$statements}
END
SQL);
            }
        }
    }

    private function dropSameTenantValidationTriggers(): void
    {
        foreach (array_reverse(array_keys($this->triggerRelationsByChild())) as $childTable) {
            foreach (['UPDATE', 'INSERT'] as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$this->validationTriggerName($childTable, $operation));
            }
        }
    }

    private function addSameTenantParentUpdateGuards(): void
    {
        $driver = DB::getDriverName();

        foreach ($this->triggerRelationsByParent() as $parentTable => $relations) {
            $triggerName = $this->parentUpdateTriggerName($parentTable);

            if ($this->isMySqlFamily()) {
                $statements = $this->mysqlParentValidationStatements($parentTable, $relations, $driver);
                DB::unprepared(<<<SQL
CREATE TRIGGER {$triggerName}
BEFORE UPDATE ON {$parentTable}
FOR EACH ROW
BEGIN
    IF NOT (NEW.tenant_id <=> OLD.tenant_id) THEN
{$statements}
    END IF;
END
SQL);

                continue;
            }

            $statements = $this->sqliteParentValidationStatements($parentTable, $relations);
            DB::unprepared(<<<SQL
CREATE TRIGGER {$triggerName}
BEFORE UPDATE OF tenant_id ON {$parentTable}
FOR EACH ROW
WHEN NEW.tenant_id IS NOT OLD.tenant_id
BEGIN
{$statements}
END
SQL);
        }
    }

    private function dropSameTenantParentUpdateGuards(): void
    {
        foreach (array_reverse(array_keys($this->triggerRelationsByParent())) as $parentTable) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->parentUpdateTriggerName($parentTable));
        }
    }

    private function addTenantDeleteProviderCleanupTrigger(): void
    {
        // MySQL DDL is not transactional. Keep this trigger creation itself
        // retry-safe when repairing or reapplying the migration.
        $this->dropTenantDeleteProviderCleanupTrigger();

        DB::unprepared(<<<'SQL'
CREATE TRIGGER tenants_provider_events_delete_cleanup
BEFORE DELETE ON tenants
FOR EACH ROW
BEGIN
    -- guest_merges restricts deletion of both referenced guests. Remove that
    -- history first, then break guest self-links before tenant cascades begin.
    DELETE FROM guest_merges
    WHERE tenant_id = OLD.id;

    UPDATE guests
    SET merged_into_guest_id = NULL,
        merged_into_guest_tenant_id = NULL
    WHERE tenant_id = OLD.id
      AND (
          merged_into_guest_id IS NOT NULL
          OR merged_into_guest_tenant_id IS NOT NULL
      );

    -- Break nullable intra-tenant links while the tenant parent still exists.
    -- Otherwise MySQL may process a sibling CASCADE first and then reject the
    -- resulting SET NULL update because tenants.id is already delete-marked.
    UPDATE inventory_items
    SET room_warehouse_id = NULL
    WHERE tenant_id = OLD.id AND room_warehouse_id IS NOT NULL;

    UPDATE menu_items
    SET inventory_item_id = NULL,
        warehouse_id = NULL
    WHERE tenant_id = OLD.id
      AND (inventory_item_id IS NOT NULL OR warehouse_id IS NOT NULL);

    UPDATE billing_invoices
    SET tenant_subscription_id = NULL
    WHERE tenant_id = OLD.id AND tenant_subscription_id IS NOT NULL;

    UPDATE billing_payments
    SET billing_invoice_id = NULL
    WHERE tenant_id = OLD.id AND billing_invoice_id IS NOT NULL;

    UPDATE billing_payment_attempts
    SET tenant_subscription_id = NULL,
        billing_invoice_id = NULL,
        billing_payment_id = NULL
    WHERE tenant_id = OLD.id
      AND (
          tenant_subscription_id IS NOT NULL
          OR billing_invoice_id IS NOT NULL
          OR billing_payment_id IS NOT NULL
      );

    UPDATE provider_events
    SET billing_payment_attempt_id = NULL,
        billing_invoice_id = NULL,
        billing_payment_id = NULL
    WHERE tenant_id = OLD.id
      AND (
          billing_payment_attempt_id IS NOT NULL
          OR billing_invoice_id IS NOT NULL
          OR billing_payment_id IS NOT NULL
      );
END
SQL);
    }

    private function dropTenantDeleteProviderCleanupTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tenants_provider_events_delete_cleanup');
    }

    /**
     * @param  list<array{0:string,1:string,2:string}>  $relations
     */
    private function mysqlValidationStatements(string $childTable, array $relations, string $driver, string $operation): string
    {
        $sharedLockClause = $this->mysqlSharedLockClause($driver);

        return collect($relations)
            ->map(function (array $relation) use ($childTable, $sharedLockClause, $operation) {
                [, $column, $parentTable] = $relation;
                $changed = $operation === 'UPDATE'
                    ? "(NOT (NEW.tenant_id <=> OLD.tenant_id) OR NOT (NEW.{$column} <=> OLD.{$column})) AND "
                    : '';

                return <<<SQL
    IF {$changed}NEW.{$column} IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM {$parentTable}
        WHERE id = NEW.{$column} AND tenant_id = NEW.tenant_id
        {$sharedLockClause}
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'same-tenant violation: {$childTable}.{$column}';
    END IF;
SQL;
            })
            ->implode("\n");
    }

    /**
     * @param  list<array{0:string,1:string,2:string}>  $relations
     */
    private function sqliteValidationStatements(string $childTable, array $relations, string $operation): string
    {
        return collect($relations)
            ->map(function (array $relation) use ($childTable, $operation) {
                [, $column, $parentTable] = $relation;
                $changed = $operation === 'UPDATE'
                    ? "(NEW.tenant_id IS NOT OLD.tenant_id OR NEW.{$column} IS NOT OLD.{$column})\n      AND "
                    : '';

                return <<<SQL
    SELECT RAISE(ABORT, 'same-tenant violation: {$childTable}.{$column}')
    WHERE {$changed}NEW.{$column} IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM {$parentTable}
          WHERE id = NEW.{$column} AND tenant_id = NEW.tenant_id
      );
SQL;
            })
            ->implode("\n");
    }

    /**
     * @param  list<array{0:string,1:string,2:string}>  $relations
     */
    private function mysqlParentValidationStatements(string $parentTable, array $relations, string $driver): string
    {
        $sharedLockClause = $this->mysqlSharedLockClause($driver);

        return collect($relations)
            ->map(function (array $relation) use ($parentTable, $sharedLockClause) {
                [$childTable, $column] = $relation;

                return <<<SQL
        IF EXISTS (
            SELECT 1 FROM {$childTable}
            WHERE {$column} = OLD.id
              AND NOT (tenant_id <=> NEW.tenant_id)
            {$sharedLockClause}
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'same-tenant parent move: {$parentTable}.tenant_id';
        END IF;
SQL;
            })
            ->implode("\n");
    }

    private function mysqlSharedLockClause(string $driver): string
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Unsupported MySQL-family driver: {$driver}.");
        }

        // Portable across MySQL 8 and MariaDB even when Laravel is configured
        // with the usual `mysql` connection for a MariaDB server.
        return 'LOCK IN SHARE MODE';
    }

    private function repairAndAssertGuestShadowKey(): void
    {
        DB::table('guests')->update([
            self::GUEST_LINK_TENANT_COLUMN => DB::raw(
                'CASE WHEN merged_into_guest_id IS NULL THEN NULL ELSE tenant_id END'
            ),
        ]);

        $invalid = DB::table('guests')
            ->where(function ($query) {
                $query->whereNull('merged_into_guest_id')
                    ->whereNotNull(self::GUEST_LINK_TENANT_COLUMN);
            })
            ->orWhere(function ($query) {
                $query->whereNotNull('merged_into_guest_id')
                    ->where(function ($nested) {
                        $nested->whereNull(self::GUEST_LINK_TENANT_COLUMN)
                            ->orWhereColumn(self::GUEST_LINK_TENANT_COLUMN, '!=', 'tenant_id');
                    });
            })
            ->exists();

        if ($invalid) {
            throw new RuntimeException('Tenant integrity failed: guests merged target shadow key is inconsistent.');
        }
    }

    /**
     * @param  list<array{0:string,1:string,2:string}>  $relations
     */
    private function sqliteParentValidationStatements(string $parentTable, array $relations): string
    {
        return collect($relations)
            ->map(function (array $relation) use ($parentTable) {
                [$childTable, $column] = $relation;

                return <<<SQL
    SELECT RAISE(ABORT, 'same-tenant parent move: {$parentTable}.tenant_id')
    WHERE EXISTS (
        SELECT 1 FROM {$childTable}
        WHERE {$column} = OLD.id
          AND tenant_id IS NOT NEW.tenant_id
    );
SQL;
            })
            ->implode("\n");
    }

    private function assertRelationsAreValid(): void
    {
        foreach ($this->allRelations() as [$childTable, $column, $parentTable]) {
            $relation = DB::table("{$childTable} as child")
                ->leftJoin("{$parentTable} as parent", 'parent.id', '=', "child.{$column}")
                ->whereNotNull("child.{$column}");

            if ((clone $relation)->whereNull('parent.id')->exists()) {
                throw new RuntimeException(
                    "Tenant integrity failed: {$childTable}.{$column} contains an unknown {$parentTable} id.",
                );
            }

            if ((clone $relation)
                ->whereNotNull('child.tenant_id')
                ->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')
                ->exists()) {
                throw new RuntimeException(
                    "Tenant integrity failed: {$childTable}.{$column} crosses tenant boundaries.",
                );
            }

            if ($childTable === 'provider_events'
                && (clone $relation)->whereNull('child.tenant_id')->exists()) {
                throw new RuntimeException(
                    "Tenant integrity failed: {$childTable}.{$column} is linked but tenant_id is null.",
                );
            }
        }
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function allRelations(): array
    {
        return [
            ['guests', 'merged_into_guest_id', 'guests'],
            ...array_map(
                fn (array $relation) => array_slice($relation, 0, 3),
                $this->restrictRelations,
            ),
            ...$this->triggerRelations,
        ];
    }

    /** @return array<string, list<array{0:string,1:string,2:string}>> */
    private function triggerRelationsByChild(): array
    {
        $grouped = [];

        foreach ($this->triggerRelations as $relation) {
            $grouped[$relation[0]][] = $relation;
        }

        return $grouped;
    }

    /** @return array<string, list<array{0:string,1:string,2:string}>> */
    private function triggerRelationsByParent(): array
    {
        $grouped = [];

        foreach ($this->triggerRelations as $relation) {
            $grouped[$relation[2]][] = $relation;
        }

        return $grouped;
    }

    private function guestSyncTriggerName(string $operation): string
    {
        return 'guests_merged_tenant_'.strtolower($operation).'_sync';
    }

    private function validationTriggerName(string $childTable, string $operation): string
    {
        return $childTable.'_same_tenant_'.strtolower($operation);
    }

    private function parentUpdateTriggerName(string $parentTable): string
    {
        return $parentTable.'_same_tenant_parent_update';
    }

    private function assertSupportedDriver(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException('Same-tenant relation triggers require MySQL, MariaDB, or SQLite.');
        }
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
