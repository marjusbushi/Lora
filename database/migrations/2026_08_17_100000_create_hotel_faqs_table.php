<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Njohuritë e hotelit për Lora AI Chat: FAQ per-tenant që AI-ja i përdor si
// të VETMIN burim përgjigjesh drejt mysafirëve (propozimi MHQ #22).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('question', 300);
            $table->text('answer');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_faqs');
    }
};
