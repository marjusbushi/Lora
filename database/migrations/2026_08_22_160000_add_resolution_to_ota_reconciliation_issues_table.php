<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Staff-resolved reconciliation issues ("extended on desk"): the reason, who
// resolved it, and a fingerprint of the PMS side at that moment so the nightly
// checker keeps the card closed while nothing changed — and reopens it if it does.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ota_reconciliation_issues', function (Blueprint $table) {
            $table->string('resolution', 40)->nullable()->after('resolved_at');
            $table->unsignedBigInteger('resolved_by')->nullable()->after('resolution');
            $table->string('resolution_fingerprint', 64)->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('ota_reconciliation_issues', function (Blueprint $table) {
            $table->dropColumn(['resolution', 'resolved_by', 'resolution_fingerprint']);
        });
    }
};
