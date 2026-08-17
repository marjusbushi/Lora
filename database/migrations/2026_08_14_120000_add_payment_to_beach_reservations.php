<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beach_reservations', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('confirmation_token');
            $table->string('pok_order_id', 64)->nullable()->after('paid_at');
            $table->index(['tenant_id', 'pok_order_id'], 'beach_reservations_pok_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('beach_reservations', function (Blueprint $table) {
            $table->dropIndex('beach_reservations_pok_order_index');
            $table->dropColumn(['paid_at', 'pok_order_id']);
        });
    }
};
