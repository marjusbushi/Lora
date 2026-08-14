<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Çmimet e reja të katalogut (2026-08-14) vlejnë PËR TË GJITHË — edhe për
 * tenant-ët ekzistues: pa këtë backfill, pricing_snapshot i ngrirë i secilit
 * entitlement fiton mbi katalogun (array_replace në summary) dhe faturat
 * vazhdojnë me çmimet e vjetra derisa dikush të ri-ruante abonimin me dorë.
 * Vendim i pronarit: pa grandfathering — negociatat e veçanta shprehen me
 * discount_override_percent, jo me snapshot-e çmimesh të vjetra.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (config('lora_modules.modules', []) as $code => $module) {
            DB::table('tenant_module_entitlements')
                ->where('module_code', $code)
                ->update([
                    'pricing_snapshot' => json_encode($module),
                    'unit_price_cents' => $module['unit_price_cents'] ?? null,
                    'percentage_bps' => $module['percentage_bps'] ?? null,
                ]);
        }
    }

    public function down(): void
    {
        // E pakthyeshme me vetëdije: snapshot-et e vjetra nuk ruhen askund.
        // Rikthimi i çmimeve të vjetra bëhet vetëm me një migrim të ri forward.
    }
};
