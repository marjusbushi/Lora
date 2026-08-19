<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OTA offers (Renato 2026-08-19): a time-boxed, per-channel discount the
     * hotel activates in the OTA's extranet; Lora COMPENSATES the pushed price
     * (push = target ÷ (1 − discount)) so the guest sees "was 50 → now 40"
     * while the hotel still nets its canonical price. Deliberately NOT a
     * season: a season changes the base for every channel including the
     * hotel's own website, while an offer touches only one OTA's rate plan.
     */
    public function up(): void
    {
        Schema::create('pricing_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('channel', 20); // booking | expedia | airbnb
            $table->decimal('discount_pct', 5, 2);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'channel', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_offers');
    }
};
