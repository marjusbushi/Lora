<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shift_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->decimal('opening_amount', 12, 2)->default(0);
            $table->decimal('expected_amount', 12, 2)->nullable();
            $table->decimal('counted_amount', 12, 2)->nullable();
            $table->decimal('over_short', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['pos_shift_id', 'currency'], 'pos_shift_currencies_shift_currency_unique');
            $table->index(['tenant_id', 'currency'], 'pos_shift_currencies_tenant_currency_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_shift_currencies');
    }
};
