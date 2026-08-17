<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plazhi kthehet OPT-IN (task MHQ #350, gjetje Codex në PR-në e promovimit #456).
 *
 * Migrimi 2026_08_14_100500 e ndezte modulin e plazhit ME PAGESË (enabled=true
 * me çmim katalogu) + billing_access për ÇDO tenant — në prodhim do faturonte
 * hotelet ekzistuese pa e kërkuar, kundër parimit të ratifikuar "katalogu i ri
 * prek vetëm aktivizime të reja". Ai migrim është ekzekutuar (append-only, s'e
 * prekim); KY e kthen gjendjen te e sakta: kush e PËRDOR realisht plazhin (ka
 * zona të krijuara) e mban të ndezur — kushdo tjetër kthehet i fikur, pa asnjë
 * rresht faturimi, derisa ta aktivizojë vetë nga paneli.
 *
 * Gjendja e mëparshme ruhet në një tabelë rezervë që down() të bëjë rollback
 * EKZAKT (gate-i mysql-upgrade e krahason DB-në byte-për-byte pas rollback-ut)
 * — i njëjti patern si backfill-i 2026_08_14_210000.
 */
return new class extends Migration
{
    private const BACKUP = 'beach_opt_in_backup_20260817';

    public function up(): void
    {
        // E mbrojtur: në ri-ekzekutim rezerva EKZISTUESE nuk mbishkruhet.
        if (! Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $table) {
                $table->unsignedBigInteger('entitlement_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->longText('tenant_metadata')->nullable();
            });
        }

        // Snapshot i plotë PARA iterimit: paginimi offset i each() do kapërcente
        // rreshta ndërsa update-i i heq nga filtri (gjetje Codex #458).
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

            $tenant = DB::table('tenants')->where('id', $row->tenant_id)->first();

            DB::table(self::BACKUP)->updateOrInsert(
                ['entitlement_id' => $row->id],
                ['tenant_id' => $row->tenant_id, 'tenant_metadata' => $tenant?->metadata],
            );

            // Vetëm kolonat e synuara — pa updated_at, që rollback-u të jetë
            // byte-për-byte identik me gjendjen para migrimit.
            DB::table('tenant_module_entitlements')
                ->where('id', $row->id)
                ->update(['enabled' => false]);

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
        if (! Schema::hasTable(self::BACKUP)) {
            return;
        }

        // Rikthim ekzakt i gjendjes së mëparshme nga rezerva, rresht për rresht.
        DB::table(self::BACKUP)->orderBy('entitlement_id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('tenant_module_entitlements')
                    ->where('id', $row->entitlement_id)
                    ->update(['enabled' => true]);

                DB::table('tenants')->where('id', $row->tenant_id)->update([
                    'metadata' => $row->tenant_metadata,
                ]);
            }
        });

        Schema::drop(self::BACKUP);
    }
};
