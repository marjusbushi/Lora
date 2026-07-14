<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POK order ids are unique per merchant account, and every hotel (tenant) has
 * its own POK merchant — so two hotels may legitimately receive the same
 * order id. Re-key the uniques to (tenant_id, pok_order_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['pok_order_id']);
            $table->unique(['tenant_id', 'pok_order_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['pok_order_id']);
            $table->unique(['tenant_id', 'pok_order_id']);
        });
    }

    public function down(): void
    {
        // Release rollback is only allowed while the application is still in
        // maintenance mode, before tenant-scoped duplicate order ids can be
        // accepted. Restore the exact pre-migration schema; if that invariant
        // is no longer true, MySQL fails safely instead of silently weakening
        // the original uniqueness guarantee.
        $this->ensureTenantIndexSurvives('reservations');
        $this->ensureTenantIndexSurvives('payments');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'pok_order_id']);
            $table->unique('pok_order_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'pok_order_id']);
            $table->unique('pok_order_id');
        });
    }

    private function ensureTenantIndexSurvives(string $tableName): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $uniqueName = "{$tableName}_tenant_id_pok_order_id_unique";
        $hasTenantIndex = collect(Schema::getIndexes($tableName))
            ->contains(fn (array $index) => $index['name'] !== $uniqueName
                && ($index['columns'][0] ?? null) === 'tenant_id');

        if (! $hasTenantIndex) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->index('tenant_id', "{$tableName}_tenant_id_foreign");
            });
        }
    }
};
