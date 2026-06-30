<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an OTA booking is paid: 'ota' = the guest PREPAID online (the hotel is
     * paid by the OTA, minus commission) so the guest owes nothing for the room;
     * 'property' = pay at the hotel. null for manual/direct bookings. Drives the
     * "Paguar nga OTA" badge + the OTA prepayment recorded on the folio.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('payment_collect')->nullable()->after('channel_ref');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('payment_collect');
        });
    }
};
