<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shkalla e kontratave (1/2/3/5 vjet → 10/15/20/30% zbritje) zëvendëson zbritjen
 * e vetme vjetore 20%. VETËM kolona shtesë (pa ->change(): rindërtimi i tabelës
 * në SQLite përplaset me trigger-at e integritetit same-tenant):
 * - contract_years: gjatësia e zgjedhur e kontratës (default 1 → 10%).
 * - discount_override_percent: negociatë e veçantë per-tenant; NULL = shkalla.
 * Kolona e vjetër annual_discount_percent mbetet e paprekur dhe NUK lexohet më —
 * abonimet ekzistuese kalojnë në shkallën e re (vendim i pronarit, 2026-08-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Të mbrojtura një-nga-një: ri-ekzekutimi mbi çdo gjendje është no-op i pastër.
        if (! Schema::hasColumn('tenant_subscriptions', 'contract_years')) {
            Schema::table('tenant_subscriptions', function (Blueprint $table) {
                $table->unsignedTinyInteger('contract_years')->default(1)->after('billing_cycle');
            });
        }

        if (! Schema::hasColumn('tenant_subscriptions', 'discount_override_percent')) {
            Schema::table('tenant_subscriptions', function (Blueprint $table) {
                $table->unsignedTinyInteger('discount_override_percent')->nullable()->after('annual_discount_percent');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['contract_years', 'discount_override_percent']);
        });
    }
};
