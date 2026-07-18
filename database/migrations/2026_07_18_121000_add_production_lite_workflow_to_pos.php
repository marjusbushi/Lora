<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->decimal('subtotal_amount', 10, 2)->default(0)->after('payment_method');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('subtotal_amount');
            $table->string('discount_reason', 255)->nullable()->after('discount_amount');
            $table->boolean('is_complimentary')->default(false)->after('discount_reason');
            $table->timestamp('cancelled_at')->nullable()->after('business_date');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable()->after('cancelled_by');
            $table->timestamp('refunded_at')->nullable()->after('cancellation_reason');
            $table->foreignId('refunded_by')->nullable()->after('refunded_at')->constrained('users')->nullOnDelete();
            $table->string('refund_reason', 255)->nullable()->after('refunded_by');
            $table->index(['tenant_id', 'business_date', 'status'], 'pos_orders_business_status_index');
        });

        Schema::create('pos_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('pos_order_id')->constrained('pos_orders')->cascadeOnDelete();
            $table->foreignId('pos_shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->enum('direction', ['in', 'out'])->default('in');
            $table->enum('method', ['cash', 'card', 'room_charge']);
            $table->decimal('amount', 10, 2);
            $table->foreignId('refunded_from_id')->nullable()->constrained('pos_order_payments')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('refunded_from_id');
            $table->index(['tenant_id', 'pos_shift_id', 'paid_at'], 'pos_payments_shift_date_index');
            $table->index(['tenant_id', 'method', 'paid_at'], 'pos_payments_method_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_payments');

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropIndex('pos_orders_business_status_index');
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'subtotal_amount', 'discount_amount', 'discount_reason', 'is_complimentary',
                'cancelled_at', 'cancellation_reason', 'refunded_at', 'refund_reason',
            ]);
        });
    }
};
