<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40);
            $table->string('provider_attempt_id', 191)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->string('currency', 3)->default('EUR');
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('attempted_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_attempt_id']);
            $table->index(['tenant_id', 'billing_invoice_id'], 'billing_attempt_tenant_invoice_idx');
        });

        Schema::table('provider_events', function (Blueprint $table) {
            $table->foreignId('billing_payment_attempt_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->foreignId('billing_invoice_id')->nullable()->after('billing_payment_attempt_id')->constrained()->nullOnDelete();
            $table->foreignId('billing_payment_id')->nullable()->after('billing_invoice_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provider_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_payment_attempt_id');
            $table->dropConstrainedForeignId('billing_invoice_id');
            $table->dropConstrainedForeignId('billing_payment_id');
        });

        Schema::dropIfExists('billing_payment_attempts');
    }
};
