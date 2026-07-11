<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_domains')) {
            return;
        }

        $tenantId = DB::table('tenant_domains')
            ->where('domain', 'villamucho.com')
            ->value('tenant_id');

        if (! $tenantId) {
            return;
        }

        DB::table('tenant_domains')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'domain' => 'admin.villamucho.com',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_domains')) {
            DB::table('tenant_domains')->where('domain', 'admin.villamucho.com')->delete();
        }
    }
};
