<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every price the autopilot touches, with enough to undo it: the previous
 * override price (NULL = there was none — revert deletes the override) and
 * the applied one. reverted_at marks a used "Kthe".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_autopilot_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('old_price', 10, 2)->nullable();
            $table->decimal('new_price', 10, 2);
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['room_type_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_autopilot_logs');
    }
};
