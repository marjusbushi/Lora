<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beach_reservations', function (Blueprint $table) {
            // cash | card (në plazh) | online (POK) — plotësohet bashkë me paid_at.
            $table->string('payment_method', 10)->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('beach_reservations', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
