<?php

namespace Tests\Feature;

use App\Models\BeachZone;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plazhi opt-in (task #350, gjetje Codex PR #456): migrimi korrigjues fik
 * entitlement-in e ndezur automatikisht VETËM për tenant-ët që s'e përdorin
 * plazhin (pa zona) — zero faturim i pakërkuar; përdoruesit realë të paprekur.
 */
class BeachOptInMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runCorrection(): void
    {
        $migration = require database_path('migrations/2026_08_17_230000_beach_entitlements_opt_in_only.php');
        $migration->up();
    }

    private function enableBeach(int $tenantId): void
    {
        DB::table('tenant_module_entitlements')->updateOrInsert(
            ['tenant_id' => $tenantId, 'module_code' => 'beach'],
            ['enabled' => true, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('tenants')->where('id', $tenantId)->update([
            'metadata' => json_encode(['billing_access' => ['modules' => ['beach' => true]]]),
        ]);
    }

    public function test_unused_beach_is_switched_off_while_real_users_keep_it(): void
    {
        $tenant = Tenant::query()->sole();
        $this->enableBeach($tenant->id);

        // Pa zona → duhet fikur (rasti i prodhimit).
        $this->runCorrection();

        $row = DB::table('tenant_module_entitlements')
            ->where('tenant_id', $tenant->id)->where('module_code', 'beach')->first();
        $this->assertSame(0, (int) $row->enabled);
        $meta = json_decode((string) DB::table('tenants')->where('id', $tenant->id)->value('metadata'), true);
        $this->assertFalse($meta['billing_access']['modules']['beach']);

        // Rollback EKZAKT (gate-i mysql-upgrade): down() rikthen gjendjen e
        // mëparshme nga rezerva dhe e fshin tabelën e saj.
        $migration = require database_path('migrations/2026_08_17_230000_beach_entitlements_opt_in_only.php');
        $migration->down();
        $row = DB::table('tenant_module_entitlements')
            ->where('tenant_id', $tenant->id)->where('module_code', 'beach')->first();
        $this->assertSame(1, (int) $row->enabled);
        $meta = json_decode((string) DB::table('tenants')->where('id', $tenant->id)->value('metadata'), true);
        $this->assertTrue($meta['billing_access']['modules']['beach']);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('beach_opt_in_backup_20260817'));

        // Me zona → ri-ndezur nga paneli, korrigjimi NUK e prek më (idempotent
        // + rasti i staging-ut ku plazhi përdoret realisht).
        $this->enableBeach($tenant->id);
        BeachZone::create(['name' => 'Zona A', 'price_per_day' => 500]);
        $this->runCorrection();

        $row = DB::table('tenant_module_entitlements')
            ->where('tenant_id', $tenant->id)->where('module_code', 'beach')->first();
        $this->assertSame(1, (int) $row->enabled);
        $meta = json_decode((string) DB::table('tenants')->where('id', $tenant->id)->value('metadata'), true);
        $this->assertTrue($meta['billing_access']['modules']['beach']);
    }
}
