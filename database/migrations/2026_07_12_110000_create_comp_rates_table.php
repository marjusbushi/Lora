<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Competitor nightly prices (rate shopping, Phase 1 — display only).
     * One row per (competitor, stay date, snapshot date): the same future date
     * is re-fetched on every snapshot so price MOVEMENT is visible over time.
     * Smart Pricing reads only the latest snapshot per date for its "Tregu"
     * summary; history stays for Phase 2/3 (trends + alerts).
     */
    public function up(): void
    {
        Schema::create('comp_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('competitor');
            $table->date('date');
            $table->decimal('price', 10, 2);
            $table->string('currency', 8)->default('EUR');
            $table->string('source', 40)->default('google_hotels');
            $table->date('snapshot_date');
            $table->timestamps();

            $table->unique(['tenant_id', 'competitor', 'date', 'snapshot_date'], 'comp_rates_snapshot_unique');
            $table->index(['tenant_id', 'date', 'snapshot_date'], 'comp_rates_date_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_rates');
    }
};
