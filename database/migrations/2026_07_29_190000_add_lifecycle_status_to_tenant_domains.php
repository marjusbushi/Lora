<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom-domain lifecycle: pending_dns (client must point the A record)
     * → provisioning (Forge site + certificate requested) → active, with
     * failed + status_message for surfaced errors. Existing rows were set up
     * by hand and already serve traffic — they backfill straight to active.
     *
     * Plain native ADD COLUMNs only — no SQLite table rebuild (the #6996
     * lesson); tenant_domains carries no triggers but the rule stands.
     */
    public function up(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->string('status', 20)->default('pending_dns')->after('is_primary');
            $table->string('status_message', 255)->nullable()->after('status');
            $table->timestamp('verified_at')->nullable()->after('status_message');
        });

        DB::table('tenant_domains')->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_message', 'verified_at']);
        });
    }
};
