<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        // Never restore the GLOBAL uniques — with two tenants sharing an order
        // id that would fail mid-rollback. A plain index keeps lookups fast.
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'pok_order_id']);
            $table->index('pok_order_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'pok_order_id']);
            $table->index('pok_order_id');
        });
    }
};
