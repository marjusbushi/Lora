<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Katalogu i çmimeve është PLATFORMË-GLOBAL (një për gjithë Lora-n),
        // prandaj tabela QËLLIMISHT s'ka tenant_id. NULL = vlen config-u
        // (lora_modules); vetëm fushat jo-NULL mbivendosin çmimin bazë.
        Schema::create('catalog_price_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('module_code', 60)->unique();
            $table->unsignedInteger('unit_price_cents')->nullable();
            $table->unsignedInteger('first_unit_price_cents')->nullable();
            $table->unsignedInteger('excess_unit_price_cents')->nullable();
            $table->unsignedSmallInteger('tier_limit')->nullable();
            $table->unsignedInteger('percentage_bps')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_price_overrides');
    }
};
