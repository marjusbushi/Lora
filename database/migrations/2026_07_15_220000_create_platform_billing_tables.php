<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 32)->nullable()->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->string('currency', 3)->default('EUR');
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('amount_paid_cents')->default(0);
            $table->date('period_starts_on')->nullable();
            $table->date('period_ends_on')->nullable();
            $table->timestamp('issued_at')->nullable()->index();
            $table->date('due_on')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('billing_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24)->default('module');
            $table->string('module_code', 40)->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->unsignedBigInteger('unit_amount_cents');
            $table->unsignedBigInteger('amount_cents');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number', 32)->nullable()->unique();
            $table->string('provider', 40)->default('manual');
            $table->string('provider_payment_id', 191)->nullable();
            $table->string('method', 32);
            $table->string('status', 20)->default('completed')->index();
            $table->string('currency', 3)->default('EUR');
            $table->unsignedBigInteger('amount_cents');
            $table->string('reference', 191)->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_payment_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40);
            $table->string('external_id', 191);
            $table->string('event_type', 120);
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_invoice_lines');
        Schema::dropIfExists('billing_invoices');
    }
};
