<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->index(['tenant_id', 'email', 'deleted_at'], 'guests_tenant_email_deleted_index');
            $table->index(['tenant_id', 'phone', 'deleted_at'], 'guests_tenant_phone_deleted_index');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'guest_id'], 'reservations_tenant_status_guest_index');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_tenant_status_guest_index');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex('guests_tenant_email_deleted_index');
            $table->dropIndex('guests_tenant_phone_deleted_index');
        });
    }
};
