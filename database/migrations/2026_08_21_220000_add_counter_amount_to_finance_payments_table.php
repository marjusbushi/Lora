<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cross-currency transfers: the amount that ARRIVES in the destination
// account's currency (amount × applied exchange rate). Null for same-currency
// transfers — the counter side keeps valuing the row at `amount`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->decimal('counter_amount', 12, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('finance_payments', function (Blueprint $table) {
            $table->dropColumn('counter_amount');
        });
    }
};
