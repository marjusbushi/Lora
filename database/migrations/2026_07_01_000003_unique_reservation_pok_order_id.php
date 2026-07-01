<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One POK order = one reservation. payments.pok_order_id is already UNIQUE; mirror it on
     * reservations so any future retry/manual-re-order/import that reused an id fails LOUD at
     * insert instead of silently mis-settling one of two rows (the webhook binds by ->first()).
     * SQLite allows multiple NULLs in a unique index, so all non-POK reservations are unaffected.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unique('pok_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['pok_order_id']);
        });
    }
};
