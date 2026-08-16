<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REFUND_TENANT_COLUMN = 'refunded_from_tenant_id';

    private const REFUND_FOREIGN = 'pos_order_payments_refund_tenant_fk';

    private const REFUND_INDEX = 'pos_order_payments_refund_tenant_idx';

    private const REFUND_PARENT_UNIQUE = 'pos_order_payments_tenant_id_id_unique';

    private const TENANT_DELETE_TRIGGER = 'tenants_pos_refunds_delete_cleanup';

    /** @var list<array{0:string,1:string,2:string,3:string,4:string}> */
    private array $restrictRelations = [
        [
            'pos_order_payments',
            'pos_order_id',
            'pos_orders',
            'pos_order_payments_order_tenant_fk',
            'pos_order_payments_tenant_order_idx',
        ],
        [
            'pos_order_rounds',
            'pos_order_id',
            'pos_orders',
            'pos_order_rounds_order_tenant_fk',
            'pos_order_rounds_tenant_order_idx',
        ],
    ];

    /**
     * These nullable relations already use single-column ON DELETE SET NULL
     * foreign keys. Triggers enforce tenant equality without changing that
     * deletion behavior.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private array $triggerRelations = [
        ['pos_order_items', 'pos_order_round_id', 'pos_order_rounds'],
        ['pos_order_payments', 'pos_shift_id', 'pos_shifts'],
        ['pos_orders', 'pos_table_id', 'pos_tables'],
    ];

    public function up(): void
    {
        $this->assertSupportedDriver();
        $this->assertRelationsAreValid();

        $this->replaceRefundForeignWithTenantAwareSetNullForeign();
        $this->addRestrictSameTenantForeignKeys();
        // SQLite rebuilds a table when adding foreign keys and drops its
        // triggers. Install the self-reference sync only after all DDL on the
        // payment table is complete.
        $this->addRefundTenantSyncTriggers();
        $this->repairAndAssertRefundShadowKey();
        $this->addSameTenantValidationTriggers();
        $this->addSameTenantParentUpdateGuards();
        $this->addTenantDeleteRefundCleanupTrigger();
    }

    public function down(): void
    {
        $this->dropTenantDeleteRefundCleanupTrigger();
        $this->dropSameTenantParentUpdateGuards();
        $this->dropSameTenantValidationTriggers();
        $this->dropRefundTenantSyncTriggers();
        $this->dropRestrictSameTenantForeignKeys();
        $this->restoreRefundSimpleForeign();
    }

    /**
     * MySQL row triggers cannot safely query the table that fired them. A
     * synced nullable shadow key lets the self-reference enforce
     * (tenant_id, id) while ON DELETE SET NULL clears only refund-link fields.
     */
    private function replaceRefundForeignWithTenantAwareSetNullForeign(): void
    {
        Schema::table('pos_order_payments', function (Blueprint $table) {
            $table->unsignedBigInteger(self::REFUND_TENANT_COLUMN)
                ->nullable()
                ->after('refunded_from_id');
            $table->index(
                [self::REFUND_TENANT_COLUMN, 'refunded_from_id'],
                self::REFUND_INDEX,
            );
            $table->unique(['tenant_id', 'id'], self::REFUND_PARENT_UNIQUE);
        });

        DB::table('pos_order_payments')
            ->whereNotNull('refunded_from_id')
            ->update([self::REFUND_TENANT_COLUMN => DB::raw('tenant_id')]);

        $dropForeignByColumns = DB::getDriverName() === 'sqlite';
        Schema::table('pos_order_payments', function (Blueprint $table) use ($dropForeignByColumns) {
            $table->dropForeign($dropForeignByColumns
                ? ['refunded_from_id']
                : 'pos_order_payments_refunded_from_id_foreign');
            $table->foreign(
                [self::REFUND_TENANT_COLUMN, 'refunded_from_id'],
                self::REFUND_FOREIGN,
            )
                ->references(['tenant_id', 'id'])
                ->on('pos_order_payments')
                ->nullOnDelete();
        });

    }

    private function restoreRefundSimpleForeign(): void
    {
        $dropForeignByColumns = DB::getDriverName() === 'sqlite';
        Schema::table('pos_order_payments', function (Blueprint $table) use ($dropForeignByColumns) {
            $table->dropForeign($dropForeignByColumns
                ? [self::REFUND_TENANT_COLUMN, 'refunded_from_id']
                : self::REFUND_FOREIGN);
            $table->foreign('refunded_from_id')
                ->references('id')
                ->on('pos_order_payments')
                ->nullOnDelete();
        });

        Schema::table('pos_order_payments', function (Blueprint $table) {
            $table->dropIndex(self::REFUND_INDEX);
            $table->dropColumn(self::REFUND_TENANT_COLUMN);
        });

        Schema::table('pos_order_payments', function (Blueprint $table) {
            $table->dropUnique(self::REFUND_PARENT_UNIQUE);
        });
    }

    private function addRestrictSameTenantForeignKeys(): void
    {
        foreach ($this->restrictRelationsByChild() as $childTable => $relations) {
            Schema::table($childTable, function (Blueprint $table) use ($relations) {
                foreach ($relations as [, $column, $parentTable, $constraintName, $indexName]) {
                    $table->index(['tenant_id', $column], $indexName);
                    $table->foreign(['tenant_id', $column], $constraintName)
                        ->references(['tenant_id', 'id'])
                        ->on($parentTable)
                        ->cascadeOnDelete();
                }
            });
        }
    }

    private function dropRestrictSameTenantForeignKeys(): void
    {
        $dropForeignByColumns = DB::getDriverName() === 'sqlite';

        foreach (array_reverse($this->restrictRelationsByChild(), true) as $childTable => $relations) {
            Schema::table($childTable, function (Blueprint $table) use ($relations, $dropForeignByColumns) {
                foreach (array_reverse($relations) as [, $column, , $constraintName]) {
                    $table->dropForeign($dropForeignByColumns
                        ? ['tenant_id', $column]
                        : $constraintName);
                }
            });

            Schema::table($childTable, function (Blueprint $table) use ($relations) {
                foreach (array_reverse($relations) as [, , , , $indexName]) {
                    $table->dropIndex($indexName);
                }
            });
        }
    }

    private function addRefundTenantSyncTriggers(): void
    {
        foreach (['INSERT', 'UPDATE'] as $operation) {
            $triggerName = $this->refundSyncTriggerName($operation);

            if ($this->isMySqlFamily()) {
                DB::unprepared(<<<SQL
CREATE TRIGGER {$triggerName}
BEFORE {$operation} ON pos_order_payments
FOR EACH ROW
SET NEW.refunded_from_tenant_id = CASE
    WHEN NEW.refunded_from_id IS NULL THEN NULL
    ELSE NEW.tenant_id
END
SQL);

                continue;
            }

            $eventColumns = $operation === 'UPDATE'
                ? ' OF tenant_id, refunded_from_id, refunded_from_tenant_id'
                : '';
            DB::unprepared(<<<SQL
CREATE TRIGGER {$triggerName}
AFTER {$operation}{$eventColumns} ON pos_order_payments
FOR EACH ROW
WHEN NEW.refunded_from_tenant_id IS NOT (
    CASE WHEN NEW.refunded_from_id IS NULL THEN NULL ELSE NEW.tenant_id END
)
BEGIN
    UPDATE pos_order_payments
    SET refunded_from_tenant_id = CASE
        WHEN NEW.refunded_from_id IS NULL THEN NULL
        ELSE NEW.tenant_id
    END
    WHERE id = NEW.id;
END
SQL);
        }
    }

    private function dropRefundTenantSyncTriggers(): void
    {
        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->refundSyncTriggerName($operation));
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

    private function addTenantDeleteRefundCleanupTrigger(): void
    {
        $this->dropTenantDeleteRefundCleanupTrigger();

        DB::unprepared(<<<SQL
CREATE TRIGGER {$this->tenantDeleteTriggerName()}
BEFORE DELETE ON tenants
FOR EACH ROW
BEGIN
    UPDATE pos_order_payments
    SET refunded_from_id = NULL,
        refunded_from_tenant_id = NULL
    WHERE tenant_id = OLD.id
      AND (
          refunded_from_id IS NOT NULL
          OR refunded_from_tenant_id IS NOT NULL
      );
END
SQL);
    }

    private function dropTenantDeleteRefundCleanupTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.$this->tenantDeleteTriggerName());
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

    private function mysqlSharedLockClause(string $driver): string
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Unsupported MySQL-family driver: {$driver}.");
        }

        return 'LOCK IN SHARE MODE';
    }

    private function repairAndAssertRefundShadowKey(): void
    {
        DB::table('pos_order_payments')->update([
            self::REFUND_TENANT_COLUMN => DB::raw(
                'CASE WHEN refunded_from_id IS NULL THEN NULL ELSE tenant_id END'
            ),
        ]);

        $invalid = DB::table('pos_order_payments')
            ->where(function ($query) {
                $query->whereNull('refunded_from_id')
                    ->whereNotNull(self::REFUND_TENANT_COLUMN);
            })
            ->orWhere(function ($query) {
                $query->whereNotNull('refunded_from_id')
                    ->where(function ($nested) {
                        $nested->whereNull(self::REFUND_TENANT_COLUMN)
                            ->orWhereColumn(self::REFUND_TENANT_COLUMN, '!=', 'tenant_id');
                    });
            })
            ->exists();

        if ($invalid) {
            throw new RuntimeException('Tenant integrity failed: POS refund shadow key is inconsistent.');
        }
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
                ->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')
                ->exists()) {
                throw new RuntimeException(
                    "Tenant integrity failed: {$childTable}.{$column} crosses tenant boundaries.",
                );
            }
        }
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function allRelations(): array
    {
        return [
            ['pos_order_payments', 'refunded_from_id', 'pos_order_payments'],
            ...array_map(
                fn (array $relation) => array_slice($relation, 0, 3),
                $this->restrictRelations,
            ),
            ...$this->triggerRelations,
        ];
    }

    /** @return array<string, list<array{0:string,1:string,2:string,3:string,4:string}>> */
    private function restrictRelationsByChild(): array
    {
        $grouped = [];

        foreach ($this->restrictRelations as $relation) {
            $grouped[$relation[0]][] = $relation;
        }

        return $grouped;
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

    private function refundSyncTriggerName(string $operation): string
    {
        return 'pos_order_payments_refund_tenant_'.strtolower($operation).'_sync';
    }

    private function validationTriggerName(string $childTable, string $operation): string
    {
        return $childTable.'_same_tenant_'.strtolower($operation);
    }

    private function parentUpdateTriggerName(string $parentTable): string
    {
        return $parentTable.'_same_tenant_parent_update';
    }

    private function tenantDeleteTriggerName(): string
    {
        return self::TENANT_DELETE_TRIGGER;
    }

    private function assertSupportedDriver(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException('POS same-tenant enforcement requires MySQL, MariaDB, or SQLite.');
        }
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
