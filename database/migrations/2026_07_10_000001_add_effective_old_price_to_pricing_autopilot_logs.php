<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_autopilot_logs', function (Blueprint $table) {
            // The effective price before the write, even when old_price is
            // NULL because no override row existed. This is the immutable
            // baseline used to enforce a truly cumulative daily cap.
            $table->decimal('effective_old_price', 10, 2)->nullable()->after('old_price');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_autopilot_logs', function (Blueprint $table) {
            $table->dropColumn('effective_old_price');
        });
    }
};
