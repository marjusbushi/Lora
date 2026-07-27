<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency POS tenders: the customer may pay cash/card in any enabled
 * currency. `amount` KEEPS its base-currency meaning (shift totals, ledger
 * and reports stay untouched); the new columns carry what was physically
 * tendered and the rate frozen at the moment of sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_payments', function (Blueprint $table) {
            // NULL = base-currency tender (every pre-existing row).
            $table->string('currency', 3)->nullable()->after('amount');
            // The amount in the tender's own currency — what enters that currency's account.
            $table->decimal('tendered_amount', 12, 2)->nullable()->after('currency');
            // Base units per 1 unit of `currency` (operational convention), frozen at sale.
            $table->decimal('exchange_rate', 18, 6)->nullable()->after('tendered_amount');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_payments', function (Blueprint $table) {
            $table->dropColumn(['currency', 'tendered_amount', 'exchange_rate']);
        });
    }
};
