<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('reservation_id');
            $table->string('provider', 32)->default('fature_al');
            $table->string('environment', 16);
            $table->string('document_type', 32)->default('cash_invoice');
            $table->string('internal_id', 100);
            $table->string('payment_method', 20);
            $table->string('currency', 3);
            $table->decimal('total', 12, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('processing');
            $table->string('remote_id', 64)->nullable();
            $table->string('fiscal_number', 100)->nullable();
            $table->string('iic', 100)->nullable();
            $table->string('fic', 100)->nullable();
            $table->string('tcr_code', 100)->nullable();
            $table->string('business_code', 100)->nullable();
            $table->string('operator_code', 100)->nullable();
            $table->timestamp('fiscalized_at')->nullable();
            $table->text('verify_url')->nullable();
            $table->text('pdf_url')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'reservation_id', 'provider', 'environment'],
                'fiscal_documents_source_unique',
            );
            $table->unique(
                ['tenant_id', 'provider', 'environment', 'internal_id'],
                'fiscal_documents_internal_id_unique',
            );
            $table->index(['tenant_id', 'status', 'created_at'], 'fiscal_documents_status_index');
            $table->foreign(['tenant_id', 'reservation_id'], 'fiscal_documents_reservation_foreign')
                ->references(['tenant_id', 'id'])
                ->on('reservations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
