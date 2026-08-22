<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matja e përdorimit AI per-tenant (task #409, vendimi i Marjusit): me çelësin
 * QENDROR platforma paguan provider-ët — çdo thirrje AI e çdo hoteli matet dhe
 * kostohet, që koeficienti i faturimit të nxjerrë vlerën e pagesës.
 *
 * APPEND-ONLY si dëshmi faturimi: pa updated_at, asnjë rrugë kodi s'i ndryshon
 * rreshtat. Kostoja ruhet në MIKRO-USD si numër i plotë (1 token me çmim
 * $X per 1M = saktësisht X mikro-USD) — kurrë float në para.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('model', 100);
            // guest_reply | structured (klasifikues + asistenti i çmimeve) | ...
            $table->string('feature', 40);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('thinking_tokens')->default(0);
            // Gati për prompt-caching (shoferët s'e raportojnë ende — mbetet 0).
            $table->unsignedBigInteger('cached_tokens')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            // Referenca opsionale drejt bisedës — pa FK (mesazhet mund të
            // fshihen; dëshmia e faturimit mbijeton e pavarur).
            $table->unsignedBigInteger('message_thread_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Raporti mujor per-tenant — indeksi mbulon edhe FK-në e tenant_id.
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
