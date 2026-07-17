<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'check_in_date'],
                'reservations_tenant_check_in_index',
            );
            $table->index(
                ['tenant_id', 'check_out_date'],
                'reservations_tenant_check_out_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_tenant_check_in_index');
            $table->dropIndex('reservations_tenant_check_out_index');
        });
    }
};
