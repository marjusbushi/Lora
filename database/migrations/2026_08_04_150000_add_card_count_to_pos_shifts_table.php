<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            // The waiter checks the card terminal's printed total against the
            // system at close — recorded like counted_cash, not just shown.
            $table->decimal('counted_card', 12, 2)->nullable()->after('counted_cash');
            $table->decimal('card_over_short', 12, 2)->nullable()->after('over_short');
        });
    }

    public function down(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dropColumn(['counted_card', 'card_over_short']);
        });
    }
};
