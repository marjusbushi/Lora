<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ota_reconciliation_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable();
            $table->string('channel', 40);
            $table->string('external_ref', 120);
            $table->string('channex_booking_id', 120)->nullable();
            $table->string('issue_type', 40);
            $table->string('severity', 20)->default('warning');
            $table->string('status', 20)->default('open');
            $table->decimal('expected_total', 14, 2)->nullable();
            $table->decimal('actual_total', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'channel', 'external_ref', 'issue_type'],
                'ota_reconciliation_issue_unique'
            );
            $table->index(['tenant_id', 'status', 'severity'], 'ota_reconciliation_open_index');
            $table->index(['tenant_id', 'last_detected_at'], 'ota_reconciliation_last_seen_index');
            $table->foreign(['tenant_id', 'reservation_id'])
                ->references(['tenant_id', 'id'])
                ->on('reservations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ota_reconciliation_issues');
    }
};
