<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->foreignId('merged_into_guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('guest_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('primary_guest_id')->constrained('guests')->restrictOnDelete();
            $table->foreignId('secondary_guest_id')->constrained('guests')->restrictOnDelete();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('field_sources');
            $table->json('secondary_snapshot');
            $table->json('moved_counts');
            $table->string('suggestion_source', 20)->default('fallback');
            $table->timestamps();

            $table->index(['tenant_id', 'primary_guest_id']);
            $table->unique(['tenant_id', 'secondary_guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_merges');

        Schema::table('guests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_guest_id');
            $table->dropConstrainedForeignId('merged_by');
            $table->dropColumn('merged_at');
        });
    }
};
