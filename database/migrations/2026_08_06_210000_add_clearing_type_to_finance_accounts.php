<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        // Deliberate no-op: shrinking the enum with clearing rows present
        // would truncate live data. The wider enum is harmless to old code.
    }
};
