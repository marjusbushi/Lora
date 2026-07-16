<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_onboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('not_started');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->date('due_date')->nullable();
            $table->json('steps');
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index('assigned_to');
        });

        Schema::create('tenant_onboarding_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_onboarding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('step_key', 50)->nullable();
            $table->string('name');
            $table->string('disk', 30)->default('local');
            $table->string('path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index(['tenant_onboarding_id', 'step_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_onboarding_documents');
        Schema::dropIfExists('tenant_onboardings');
    }
};
