<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide key/value settings — deliberately WITHOUT tenant_id. First
 * consumer: the shared exchange-rate integration (one daily fetch for every
 * hotel instead of one per tenant). The tenant `settings` table cannot host
 * this because tenant_id is NOT NULL by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('text');
            $table->timestamps();
        });

        $this->backfillCurrencySettingsFromTenants();
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }

    /**
     * Seed the platform store from the first tenant that already has a
     * working currency-API configuration, so the platform fetch starts warm
     * and no hotel loses rates during the rollout. (In production both
     * tenants share the same ExchangeRate-API key.)
     */
    private function backfillCurrencySettingsFromTenants(): void
    {
        $sourceTenantId = DB::table('settings')
            ->where('group', 'currencies')
            ->where('key', 'api_key')
            ->where('value', '!=', '')
            ->whereNotNull('value')
            ->orderBy('tenant_id')
            ->value('tenant_id');

        if ($sourceTenantId === null) {
            return;
        }

        $rows = DB::table('settings')
            ->where('tenant_id', $sourceTenantId)
            ->where('group', 'currencies')
            ->whereIn('key', ['api_key', 'enabled', 'rates', 'updated_at'])
            ->get(['key', 'value', 'type']);

        $now = now();

        foreach ($rows as $row) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => 'currencies.'.$row->key],
                ['value' => $row->value, 'type' => $row->type, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
};
