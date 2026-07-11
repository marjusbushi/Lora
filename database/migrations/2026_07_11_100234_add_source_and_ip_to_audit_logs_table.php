<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('source', 32)->default('system')->after('causer_id')->index();
            $table->string('ip_address', 45)->nullable()->after('source');
        });

        // Preserve the meaning of existing rows: a known user means a staff action.
        DB::table('audit_logs')->whereNotNull('causer_id')->update(['source' => 'staff']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'ip_address']);
        });
    }
};
