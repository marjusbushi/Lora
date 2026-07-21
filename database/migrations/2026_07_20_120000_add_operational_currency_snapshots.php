<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('total_amount');
            $table->decimal('exchange_rate', 18, 6)->nullable()->after('currency');
            $table->decimal('total_amount_base', 14, 2)->nullable()->after('exchange_rate');
            $table->decimal('rate_before_discount_base', 14, 2)->nullable()->after('rate_before_discount');
            $table->decimal('direct_discount_amount_base', 14, 2)->nullable()->after('direct_discount_amount');
            $table->decimal('commission_amount_base', 14, 2)->nullable()->after('commission_amount');
        });

        Schema::table('folio_items', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('amount');
            $table->decimal('exchange_rate', 18, 6)->nullable()->after('currency');
            $table->decimal('amount_base', 14, 2)->nullable()->after('exchange_rate');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('exchange_rate', 18, 6)->nullable()->after('currency');
            $table->decimal('amount_base', 14, 2)->nullable()->after('exchange_rate');
        });

        // Historical rows were stored in the tenant base currency. A 1:1
        // snapshot preserves their exact meaning; no historical FX is invented.
        foreach (DB::table('tenants')->get(['id', 'currency']) as $tenant) {
            $currency = strtoupper((string) ($tenant->currency ?: 'EUR'));
            DB::table('reservations')->where('tenant_id', $tenant->id)->update([
                'currency' => $currency,
                'exchange_rate' => 1,
                'total_amount_base' => DB::raw('total_amount'),
                'rate_before_discount_base' => DB::raw('rate_before_discount'),
                'direct_discount_amount_base' => DB::raw('direct_discount_amount'),
                'commission_amount_base' => DB::raw('commission_amount'),
            ]);
            DB::table('folio_items')->where('tenant_id', $tenant->id)->update([
                'currency' => $currency,
                'exchange_rate' => 1,
                'amount_base' => DB::raw('amount'),
            ]);
            DB::table('payments')->where('tenant_id', $tenant->id)->update([
                'currency' => DB::raw("COALESCE(currency, '{$currency}')"),
                'exchange_rate' => 1,
                'amount_base' => DB::raw('amount'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['exchange_rate', 'amount_base']));
        Schema::table('folio_items', fn (Blueprint $table) => $table->dropColumn(['currency', 'exchange_rate', 'amount_base']));
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn([
            'currency', 'exchange_rate', 'total_amount_base', 'rate_before_discount_base',
            'direct_discount_amount_base', 'commission_amount_base',
        ]));
    }
};
