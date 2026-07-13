<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest messaging (Channex Messages): OTA guest conversations (Booking.com,
 * Airbnb, Expedia) mirrored into the PMS so reception replies from one Inbox.
 * Both tables are tenant-scoped (BelongsToTenant); ids are unique per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('channex_thread_id')->nullable();
            $table->string('channel')->nullable();            // booking.com / airbnb / expedia
            $table->string('channex_booking_id')->nullable();
            $table->foreignId('reservation_id')->nullable()->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('status')->default('open');        // open / closed
            $table->string('last_message_preview', 280)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'channex_thread_id']);
            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('message_thread_id')->constrained()->cascadeOnDelete();
            $table->string('channex_message_id')->nullable();
            $table->string('sender');                          // guest / host
            $table->text('body');
            $table->boolean('has_attachment')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channex_message_id']);
            $table->index(['tenant_id', 'message_thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
    }
};
