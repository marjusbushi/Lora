<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plazhi kthehet OPT-IN (task MHQ #350, gjetje Codex në PR-në e promovimit #456).
 *
 * Migrimi 2026_08_14_100500 e ndezte modulin e plazhit ME PAGESË (enabled=true
 * me çmim katalogu) + billing_access për ÇDO tenant — në prodhim do faturonte
 * hotelet ekzistuese pa e kërkuar. Ai migrim është ekzekutuar (append-only, s'e
 * prekim); KY e kthen gjendjen te e sakta: kush e PËRDOR realisht plazhin (ka
 * zona të krijuara) e mban të ndezur — kushdo tjetër kthehet i fikur, pa asnjë
 * rresht faturimi, derisa ta aktivizojë vetë nga paneli.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Snapshot i plotë PARA iterimit: each() paginon me offset dhe update-i
        // i kolonës së filtrit (enabled) do kapërcente rreshta mbi faqen e parë
        // (gjetje Codex #458).
        $rows = DB::table('tenant_module_entitlements')
            ->where('module_code', 'beach')
            ->where('enabled', true)
            ->orderBy('tenant_id')
            ->get(['id', 'tenant_id']);

        $rows->each(function (object $row) {
                $usesBeach = DB::table('beach_zones')
                    ->where('tenant_id', $row->tenant_id)
                    ->exists();

                if ($usesBeach) {
                    return; // e përdor realisht — e mban të ndezur
                }

                DB::table('tenant_module_entitlements')
                    ->where('id', $row->id)
                    ->update(['enabled' => false, 'updated_at' => now()]);

                $tenant = DB::table('tenants')->where('id', $row->tenant_id)->first();
                if (! $tenant) {
                    return;
                }

                $metadata = json_decode((string) ($tenant->metadata ?? '{}'), true);
                $metadata = is_array($metadata) ? $metadata : [];
                if (isset($metadata['billing_access']['modules']['beach'])) {
                    $metadata['billing_access']['modules']['beach'] = false;
                    DB::table('tenants')->where('id', $row->tenant_id)->update([
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // No-op i qëllimshëm: gjendja opt-in ËSHTË e synuara — rikthimi do
        // ri-ndizte faturim të pakërkuar për tenant-ët që s'e përdorin plazhin.
    }
};
