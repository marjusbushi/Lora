<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Airbnb deducts its host fee from the payout instead of invoicing
     * commission, so net parity needs the same per-channel rate plan trick as
     * Booking/Expedia: an Airbnb-specific plan receiving a compensated
     * (higher) rate while the base plan keeps the canonical price. Nullable:
     * rows without an Airbnb plan keep today's behaviour.
     */
    public function up(): void
    {
        Schema::table('channel_mappings', function (Blueprint $table) {
            if (! Schema::hasColumn('channel_mappings', 'channex_airbnb_rate_plan_id')) {
                $table->string('channex_airbnb_rate_plan_id')->nullable()->after('channex_expedia_rate_plan_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channel_mappings', function (Blueprint $table) {
            $table->dropColumn(['channex_airbnb_rate_plan_id']);
        });
    }
};
