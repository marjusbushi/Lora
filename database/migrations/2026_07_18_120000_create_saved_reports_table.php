<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('route_name');
            $table->json('filters')->nullable();
            $table->string('frequency')->nullable();
            $table->string('delivery_email')->nullable();
            $table->timestamp('next_delivery_at')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'next_delivery_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
