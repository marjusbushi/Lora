<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_issues', function (Blueprint $table) {
            // Stable issue-type key from the curated catalog (null = free-text
            // "Tjetër" or historical rows). Language-independent so the
            // recurrence fingerprint groups identical problems regardless of
            // the UI language or the reporter's wording.
            $table->string('issue_key', 64)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_issues', function (Blueprint $table) {
            $table->dropColumn('issue_key');
        });
    }
};
