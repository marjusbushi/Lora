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
        // rows are corrected in the data-cleanup pass, not here). Soft-deleted
        // rooms still carry their type, so the join is unfiltered on purpose.
        DB::statement(<<<'SQL'
            UPDATE reservations r
            JOIN rooms ro ON ro.id = r.room_id
            SET r.booked_room_type_id = ro.room_type_id
            WHERE r.booked_room_type_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booked_room_type_id');
        });
    }
};
