<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * archived_at marks a cleaning task as "off the board" (kept in the DB for
     * records). The daily housekeeping:archive-inspected job sets it on inspected
     * tasks so the board only shows live work. NOT SoftDeletes — rows stay queryable.
     */
    public function up(): void
    {
        Schema::table('cleaning_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('cleaning_tasks', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('inspected_at');
                $table->index('archived_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('cleaning_tasks', 'archived_at')) {
                $table->dropIndex(['archived_at']);
                $table->dropColumn('archived_at');
            }
        });
    }
};
