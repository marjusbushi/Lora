<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Çmimet e reja të katalogut (2026-08-14) vlejnë PËR TË GJITHË — edhe për
 * tenant-ët ekzistues: pa këtë backfill, pricing_snapshot i ngrirë i secilit
 * entitlement fiton mbi katalogun (array_replace në summary) dhe faturat
 * vazhdojnë me çmimet e vjetra derisa dikush të ri-ruante abonimin me dorë.
 * Vendim i pronarit: pa grandfathering — negociatat e veçanta shprehen me
 * discount_override_percent, jo me snapshot-e çmimesh të vjetra.
 *
 * Vlerat e vjetra ruhen në një tabelë rezervë që down() të bëjë rollback
 * EKZAKT (gate-i mysql-upgrade e krahason DB-në byte-për-byte pas rollback-ut).
 * Tabela e rezervës mund të fshihet me një migrim të mëvonshëm pasi çmimet
 * e reja të konfirmohen në prodhim.
 */
return new class extends Migration
{
    private const BACKUP = 'entitlement_pricing_backup_20260814';

    public function up(): void
    {
        // E mbrojtur: në ri-ekzekutim rezerva EKZISTUESE nuk mbishkruhet —
        // mban gjithmonë vlerat më të vjetra të vërteta.
        if (! Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $table) {
                $table->unsignedBigInteger('entitlement_id')->primary();
                $table->json('pricing_snapshot')->nullable();
                $table->integer('unit_price_cents')->nullable();
                $table->integer('percentage_bps')->nullable();
            });

            DB::statement(sprintf(
                'INSERT INTO %s (entitlement_id, pricing_snapshot, unit_price_cents, percentage_bps)
                 SELECT id, pricing_snapshot, unit_price_cents, percentage_bps FROM tenant_module_entitlements',
                self::BACKUP,
            ));
        }

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
        if (! Schema::hasTable(self::BACKUP)) {
            return;
        }

        // Rikthim ekzakt i vlerave të vjetra nga rezerva, rresht për rresht.
        DB::table(self::BACKUP)->orderBy('entitlement_id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('tenant_module_entitlements')
                    ->where('id', $row->entitlement_id)
                    ->update([
                        'pricing_snapshot' => $row->pricing_snapshot,
                        'unit_price_cents' => $row->unit_price_cents,
                        'percentage_bps' => $row->percentage_bps,
                    ]);
            }
        });

        Schema::drop(self::BACKUP);
    }
};
