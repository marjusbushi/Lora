<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_children')->default(1)->after('max_occupancy');
        });

        // Backfill: sa fëmijë maksimumi = kapaciteti - 1 (të paktën një i rritur).
        // Query builder i papërpunuar me qëllim: Eloquent do prekte updated_at dhe
        // gate-i i CI-së (mysql-upgrade) krahason checksum-e byte-për-byte pas
        // rollback-ut. CASE në vend të GREATEST — SQLite s'e njeh GREATEST.
        DB::statement('UPDATE room_types SET max_children = CASE WHEN max_occupancy > 1 THEN max_occupancy - 1 ELSE 0 END');
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('max_children');
        });
    }
};
