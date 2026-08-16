<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cikli i mësimit të Lora AI (task MHQ #334): kur Lora s'e di përgjigjen dhe
// stafi përgjigjet vetë, çifti pyetje+përgjigje ruhet si sugjerim FAQ që
// pronari ta shtojë me një klik — njohuria rritet nga bisedat reale.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_faq_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_thread_id')->nullable()->constrained()->nullOnDelete();
            $table->string('question', 500);
            $table->text('suggested_answer');
            $table->string('status', 20)->default('pending'); // pending | saved | dismissed
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('message_threads', function (Blueprint $table) {
            // Pyetja e fundit e mysafirit që Lora s'e mbuloi dot — pritet
            // përgjigja e stafit për t'u kthyer në sugjerim FAQ.
            $table->text('ai_unanswered_question')->nullable()->after('ai_suggested_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropColumn('ai_unanswered_question');
        });

        Schema::dropIfExists('hotel_faq_suggestions');
    }
};
