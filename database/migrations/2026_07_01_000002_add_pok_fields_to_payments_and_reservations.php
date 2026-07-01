<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * POK (pokpay.io) online card payments for direct website bookings.
     * - reservations.pok_order_id: the POK sdk-order this booking is being paid with; paid_at
     *   stamps when POK confirmed it (reservation flips pending → confirmed).
     * - payments.pok_order_id (UNIQUE): the folio card-payment row created once POK confirms.
     *   The unique index is the double-record guard — a duplicate/late webhook can never insert
     *   a second payment for the same order (SQLite lockForUpdate is a no-op; guard at the schema).
     */
    public function up(): void
    {
        // Idempotent per-column: SQLite commits DDL immediately, so a mid-migration failure can
        // leave some columns already added — guard each so a re-run safely completes + records.
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'pok_order_id')) {
                $table->string('pok_order_id')->nullable()->after('payment_collect');
            }
            if (! Schema::hasColumn('reservations', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('pok_order_id');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'pok_order_id')) {
                $table->string('pok_order_id')->nullable()->unique()->after('method');
            }
            if (! Schema::hasColumn('payments', 'currency')) {
                $table->string('currency', 3)->nullable()->after('pok_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['pok_order_id', 'paid_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['pok_order_id']);
            $table->dropColumn(['pok_order_id', 'currency']);
        });
    }
};
