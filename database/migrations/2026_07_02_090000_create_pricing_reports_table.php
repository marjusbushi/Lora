<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Raporti javor i çmimeve" — the weekly Gemini-narrated pricing report
 * (anomalies, pace, advice). One row per week, regenerable in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_reports', function (Blueprint $table) {
            $table->id();
            $table->date('week_start')->unique();
            $table->string('title');
            $table->text('body');
            $table->json('highlights')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_reports');
    }
};
