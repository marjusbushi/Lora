<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->timestamps();
            $table->unique(['season_id', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_rates');
    }
};
