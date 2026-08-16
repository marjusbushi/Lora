<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// WhatsApp QR-lite (task MHQ #335): lidhja e numrit të hotelit me PMS përmes
// urës Node/Baileys. Një rresht per-tenant mban gjendjen e sesionit; bisedat
// hyjnë te message_threads si kanal 'whatsapp' me jid-in e mysafirit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('disconnected'); // disconnected | pairing | connected
            $table->string('phone_number', 30)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });

        Schema::table('message_threads', function (Blueprint $table) {
            // JID = adresa WhatsApp e mysafirit (p.sh. 3556…@s.whatsapp.net).
            $table->string('whatsapp_jid', 100)->nullable()->after('channex_thread_id');
            $table->index(['tenant_id', 'whatsapp_jid']);
        });

        Schema::table('messages', function (Blueprint $table) {
            // Dedup i mesazheve hyrëse (dhe echo-ve pas dërgimit, pjesa 2).
            $table->string('whatsapp_message_id', 100)->nullable()->after('channex_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('whatsapp_message_id');
        });

        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'whatsapp_jid']);
            $table->dropColumn('whatsapp_jid');
        });

        Schema::dropIfExists('whatsapp_connections');
    }
};
