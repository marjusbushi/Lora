<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the Channex booking id on OTA reservations so guest-message threads
 * (whose webhook carries the same booking_id) can be linked to the stay and
 * shown alongside the conversation. Additive; existing rows keep it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'channex_booking_id')) {
                $table->string('channex_booking_id')->nullable()->after('channel_ref');
                $table->index(['tenant_id', 'channex_booking_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'channex_booking_id']);
            $table->dropColumn('channex_booking_id');
        });
    }
};
