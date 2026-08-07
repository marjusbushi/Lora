<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A third account type for migration/clearing accounts (e.g. "Beds24"):
     * money collected before this PMS existed is settled onto a clearing
     * account so receivables become truthful WITHOUT polluting Arka or the
     * real bank accounts (the bank report filters type='bank' and therefore
     * never sees clearing rows).
     */
    public function up(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->enum('type', ['cash', 'bank', 'clearing'])->change();
        });

        // The ledger mirrors folio payments 1:1, so the settlement method
        // must exist on both sides.
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->enum('method', ['cash', 'card', 'bank', 'pok', 'ota', 'import'])->default('cash')->change();
        });
    }

    public function down(): void
    {
        // Shrinking an enum under data TRUNCATES rows silently — refuse
        // loudly instead. A clean rollback (no clearing/import rows yet, as
        // in CI's upgrade-then-rollback check) reverts to the exact baseline.
        if (DB::table('finance_accounts')->where('type', 'clearing')->exists()
            || DB::table('finance_payments')->where('method', 'import')->exists()) {
            throw new RuntimeException(
                'Rollback refused: clearing accounts / import payments exist. '
                .'Reassign or remove those rows first — shrinking the enum would truncate them.',
            );
        }

        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->enum('type', ['cash', 'bank'])->change();
        });

        Schema::table('finance_payments', function (Blueprint $table) {
            $table->enum('method', ['cash', 'card', 'bank', 'pok', 'ota'])->default('cash')->change();
        });
    }
};
