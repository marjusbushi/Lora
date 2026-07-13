<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OTA price parity: Booking.com and Expedia run member/mobile discount
     * programs (Genius, Member Price) that lower the displayed price BELOW the
     * hotel's target. Channex has no per-channel modifier, so each channel gets
     * its OWN rate plan and the PMS pushes a compensated (higher) rate to it,
     * while the base rate plan keeps the canonical price for everything else.
     * Nullable: rows without channel plans keep today's single-plan behaviour.
     */
    public function up(): void
    {
        Schema::table('channel_mappings', function (Blueprint $table) {
            if (! Schema::hasColumn('channel_mappings', 'channex_booking_rate_plan_id')) {
                $table->string('channex_booking_rate_plan_id')->nullable()->after('channex_rate_plan_id');
            }
            if (! Schema::hasColumn('channel_mappings', 'channex_expedia_rate_plan_id')) {
                $table->string('channex_expedia_rate_plan_id')->nullable()->after('channex_booking_rate_plan_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channel_mappings', function (Blueprint $table) {
            $table->dropColumn(['channex_booking_rate_plan_id', 'channex_expedia_rate_plan_id']);
        });
    }
};
