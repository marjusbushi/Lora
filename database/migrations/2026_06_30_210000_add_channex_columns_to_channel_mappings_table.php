<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * channel_mappings was created Beds24-shaped (beds24_prop_id / beds24_room_id,
     * both required). Channex is a second channel with its own identifiers and an
     * extra level (rate plan). Rather than rewrite the table (and risk live Beds24
     * rows), add nullable Channex columns and relax the Beds24 ones so a
     * Channex-only row is valid. One row per (channel, room_type) still holds —
     * a 'channex' row fills channex_*, a 'beds24' row fills beds24_*.
     */
    public function up(): void
    {
        // Relax the Beds24 columns so a Channex row can leave them null.
        Schema::table('channel_mappings', function (Blueprint $table) {
            $table->string('beds24_prop_id')->nullable()->change();
            $table->string('beds24_room_id')->nullable()->change();
        });

        // Add Channex identifiers (guarded so a re-run is a clean no-op).
        Schema::table('channel_mappings', function (Blueprint $table) {
            if (! Schema::hasColumn('channel_mappings', 'channex_property_id')) {
                $table->string('channex_property_id')->nullable()->after('beds24_room_id');
            }
            if (! Schema::hasColumn('channel_mappings', 'channex_room_type_id')) {
                $table->string('channex_room_type_id')->nullable()->after('channex_property_id');
            }
            if (! Schema::hasColumn('channel_mappings', 'channex_rate_plan_id')) {
                $table->string('channex_rate_plan_id')->nullable()->after('channex_room_type_id');
            }
        });
    }

    public function down(): void
    {
        // Only drop what we added. We do NOT re-tighten beds24_* to NOT NULL:
        // Channex rows may have left them null, so reverting would fail — and a
        // relaxed-nullable column is harmless.
        Schema::table('channel_mappings', function (Blueprint $table) {
            $table->dropColumn(['channex_property_id', 'channex_room_type_id', 'channex_rate_plan_id']);
        });
    }
};
