<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->date('original_check_out_date')->nullable()->after('check_out_date');
            $table->decimal('early_departure_original_room_total', 12, 2)->nullable()->after('original_check_out_date');
            $table->timestamp('early_departure_scheduled_at')->nullable()->after('early_departure_original_room_total');
            $table->foreignId('early_departure_scheduled_by')->nullable()->after('early_departure_scheduled_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('early_departure_at')->nullable()->after('early_departure_scheduled_by');
            $table->foreignId('early_departure_by')->nullable()->after('early_departure_at')
                ->constrained('users')->nullOnDelete();
            $table->string('early_departure_policy')->nullable()->after('early_departure_by');
            $table->decimal('early_departure_penalty_amount', 12, 2)->nullable()->after('early_departure_policy');
            $table->text('early_departure_reason')->nullable()->after('early_departure_penalty_amount');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('early_departure_scheduled_by');
            $table->dropConstrainedForeignId('early_departure_by');
            $table->dropColumn([
                'original_check_out_date',
                'early_departure_original_room_total',
                'early_departure_scheduled_at',
                'early_departure_at',
                'early_departure_policy',
                'early_departure_penalty_amount',
                'early_departure_reason',
            ]);
        });
    }
};
