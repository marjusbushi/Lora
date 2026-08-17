<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Tenant-i bazë i testeve e ka PLAZHIN të aktivizuar VETË (opt-in real,
        // si nga paneli) — migrimi korrigjues #350 e fik automatikisht për
        // tenant-ët pa zona (sjellja e prodhimit, e provuar te
        // BeachOptInMigrationTest). Suitat e plazhit dhe faturimi testojnë
        // tenant-in "që e ka kërkuar". Shkrimi ndodh brenda transaksionit të
        // testit → rikthehet vetiu pas çdo testi.
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            DB::table('tenant_module_entitlements')
                ->where('module_code', 'beach')
                ->update(['enabled' => true]);

            DB::table('tenants')->orderBy('id')->each(function (object $tenant) {
                $metadata = json_decode((string) ($tenant->metadata ?? '{}'), true);
                $metadata = is_array($metadata) ? $metadata : [];
                if (($metadata['billing_access']['modules']['beach'] ?? null) === false) {
                    $metadata['billing_access']['modules']['beach'] = true;
                    DB::table('tenants')->where('id', $tenant->id)->update([
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
        }
    }
}
