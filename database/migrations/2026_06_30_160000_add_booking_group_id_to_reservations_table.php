<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Links the rooms of a single multi-room booking (one guest, N rooms,
            // each its own reservation row). NULL = a normal single-room booking.
            $table->string('booking_group_id')->nullable()->after('channel_ref');
            $table->index('booking_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['booking_group_id']);
            $table->dropColumn('booking_group_id');
        });
    }
};
