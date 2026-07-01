<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted demand events for pricing (holidays, festivals, diaspora peak).
 * Replaces the throwaway per-request events array the owner had to retype on
 * every AI run, and the hardcoded Holidays.php map. uplift_pct is the
 * deterministic engine's knob (Copa 2); NULL = context-only (shown/explained,
 * no automatic price effect).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('uplift_pct', 5, 2)->nullable();
            $table->string('source', 10)->default('manual'); // manual | ai | system
            $table->boolean('recurring')->default(false);    // repeats yearly (by month-day)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_events');
    }
};
