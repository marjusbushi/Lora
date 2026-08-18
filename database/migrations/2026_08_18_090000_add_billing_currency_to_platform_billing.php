<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monedha e FATURIMIT TË PLATFORMËS ndahet nga monedha OPERATIVE e hotelit.
 *
 * Bug-u që mbyll: tenant_subscriptions.currency mbushej me tenants.currency
 * (monedha me të cilën hoteli shet dhomat). Çmimet e katalogut janë GJITHMONË
 * në euro, ndaj një hotel me bazë lek e shihte abonimin e vet si "ALL 266"
 * ndërsa vlera reale ishte €266 — etiketë e gabuar, pa asnjë konvertim.
 *
 * VETËM KOLONA SHTESË — asnjë rresht ekzistues nuk preket:
 * - tenant_subscriptions.billing_currency: default 'EUR', ndaj çdo abonim
 *   ekzistues bëhet i saktë në çastin e migrimit pa asnjë UPDATE (çmimet kanë
 *   qenë euro që nga dita e parë). Kolona e vjetër `currency` mbetet e ngrirë
 *   si dëshmi historike dhe NUK lexohet/shkruhet më nga kodi.
 * - tenant_subscriptions.fx_rate_override: kurs fiks kontrate; NULL = kursi
 *   ditor i platformës (PlatformSetting currencies.rates).
 * - billing_invoices.fx_rate / fx_base: kursi i NGRIRË në çastin e lëshimit.
 *   NULL te faturat e vjetra = pa konvertim. Një dokument financiar nuk guxon
 *   të ndryshojë vlerë kur kursi lëviz nesër.
 *
 * Pa ->change() dhe pa UPDATE: rollback-u i kthen byte-t saktësisht (hapi
 * "Verify exact database rollback" i CI-t krahason checksum-e per-tabelë).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Të mbrojtura një-nga-një: ri-ekzekutimi mbi çdo gjendje është no-op i pastër.
        if (! Schema::hasColumn('tenant_subscriptions', 'billing_currency')) {
            Schema::table('tenant_subscriptions', function (Blueprint $table) {
                $table->string('billing_currency', 3)->default('EUR')->after('currency');
            });
        }

        if (! Schema::hasColumn('tenant_subscriptions', 'fx_rate_override')) {
            Schema::table('tenant_subscriptions', function (Blueprint $table) {
                $table->decimal('fx_rate_override', 18, 6)->nullable()->after('billing_currency');
            });
        }

        if (! Schema::hasColumn('billing_invoices', 'fx_rate')) {
            Schema::table('billing_invoices', function (Blueprint $table) {
                $table->decimal('fx_rate', 18, 6)->nullable()->after('currency');
            });
        }

        if (! Schema::hasColumn('billing_invoices', 'fx_base')) {
            Schema::table('billing_invoices', function (Blueprint $table) {
                $table->string('fx_base', 3)->default('EUR')->after('fx_rate');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['billing_currency', 'fx_rate_override']);
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropColumn(['fx_rate', 'fx_base']);
        });
    }
};
