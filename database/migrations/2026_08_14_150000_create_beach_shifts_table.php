<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beach_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->decimal('opening_float', 10, 2)->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            // Të ngrira në mbyllje — Z-raporti i turnit të plazhit.
            $table->decimal('expected_cash', 10, 2)->nullable();
            $table->decimal('counted_cash', 10, 2)->nullable();
            $table->decimal('over_short', 10, 2)->nullable();
            $table->decimal('cash_sales', 10, 2)->default(0);
            $table->decimal('card_sales', 10, 2)->default(0);
            $table->decimal('total_sales', 10, 2)->default(0);
            $table->integer('total_paid')->default(0);
            $table->string('closing_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'user_id', 'status'], 'beach_shifts_user_status_index');
        });

        Schema::table('beach_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('beach_shift_id')->nullable()->after('payment_method');
            $table->foreign(['tenant_id', 'beach_shift_id'], 'beach_reservations_shift_foreign')
                ->references(['tenant_id', 'id'])
                ->on('beach_shifts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('beach_reservations', function (Blueprint $table) {
            $table->dropForeign('beach_reservations_shift_foreign');
            $table->dropColumn('beach_shift_id');
        });
        Schema::dropIfExists('beach_shifts');
    }
};
