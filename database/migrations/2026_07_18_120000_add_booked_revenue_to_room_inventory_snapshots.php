<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_inventory_snapshots', function (Blueprint $table) {
            $table->decimal('booked_revenue', 12, 2)->nullable()->after('booked');
        });
    }

    public function down(): void
    {
        Schema::table('room_inventory_snapshots', function (Blueprint $table) {
            $table->dropColumn('booked_revenue');
        });
    }
};
