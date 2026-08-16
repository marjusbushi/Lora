<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Gjurmët e Lora AI Chat: cili mesazh u dërgua nga AI (etiketa '· Lora AI')
// dhe drafti në pritje i AI-t mbi thread (kur s'është e sigurt të dërgojë vetë).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('sent_by_ai')->default(false)->after('sender');
        });

        Schema::table('message_threads', function (Blueprint $table) {
            $table->text('ai_suggestion')->nullable()->after('unread_count');
            $table->timestamp('ai_suggested_at')->nullable()->after('ai_suggestion');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('sent_by_ai');
        });

        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropColumn(['ai_suggestion', 'ai_suggested_at']);
        });
    }
};
