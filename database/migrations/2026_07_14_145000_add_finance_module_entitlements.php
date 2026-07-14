<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $module = config('lora_modules.modules.finance', []);
        $now = now();

        DB::table('tenants')->orderBy('id')->each(function (object $tenant) use ($module, $now) {
            DB::table('tenant_module_entitlements')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'module_code' => 'finance'],
                [
                    'enabled' => true,
                    'quantity' => 1,
                    'unit_price_cents' => $module['unit_price_cents'] ?? null,
                    'percentage_bps' => $module['percentage_bps'] ?? null,
                    'pricing_snapshot' => json_encode($module, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $metadata = json_decode((string) ($tenant->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['billing_access'] = is_array($metadata['billing_access'] ?? null)
                ? $metadata['billing_access']
                : [];
            $metadata['billing_access']['modules'] = is_array($metadata['billing_access']['modules'] ?? null)
                ? $metadata['billing_access']['modules']
                : [];
            $metadata['billing_access']['modules']['finance'] = true;

            DB::table('tenants')->where('id', $tenant->id)->update([
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('tenant_module_entitlements')->where('module_code', 'finance')->delete();

        DB::table('tenants')->orderBy('id')->each(function (object $tenant) {
            $metadata = json_decode((string) ($tenant->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            unset($metadata['billing_access']['modules']['finance']);

            DB::table('tenants')->where('id', $tenant->id)->update([
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);
        });
    }
};
