<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // The room TYPE the guest booked — stamped once at creation and never
            // touched by room moves, so it stays honest after any reassignment
            // (the current room's type lies once a guest is moved cross-type).
            // NULL only for legacy rows the backfill below cannot resolve.
            $table->foreignId('booked_room_type_id')->nullable()->after('room_id')
                ->constrained('room_types')->nullOnDelete();
        });

        // Backfill from the CURRENT room's type: correct for every row except
        // guests already moved cross-type before this column existed (those few
        // rows are corrected in the data-cleanup pass, not here). Correlated
        // subquery instead of UPDATE..JOIN so the same statement runs on MySQL
        // (prod) AND the SQLite test database; soft-deleted rooms still carry
        // their type, so the lookup is unfiltered on purpose.
        DB::table('reservations')
            ->whereNull('booked_room_type_id')
            ->update([
                'booked_room_type_id' => DB::raw('(select room_type_id from rooms where rooms.id = reservations.room_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booked_room_type_id');
        });
    }
};
