<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_anchor_day')->default(1)->after('billing_cycle');
            $table->timestamp('next_billing_at')->nullable()->after('current_period_ends_at')->index();
            $table->timestamp('last_billed_at')->nullable()->after('next_billing_at');
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->after('number')->unique();
        });

        DB::table('tenant_subscriptions')->orderBy('id')->each(function (object $subscription): void {
            $startsAt = Carbon::parse($subscription->starts_at ?? $subscription->created_at ?? now());
            $nextBillingAt = $subscription->current_period_ends_at
                ? Carbon::parse($subscription->current_period_ends_at)->addDay()->startOfDay()
                : ($subscription->billing_cycle === 'annual'
                    ? $startsAt->copy()->addYearNoOverflow()->startOfDay()
                    : $startsAt->copy()->addMonthNoOverflow()->startOfDay());

            DB::table('tenant_subscriptions')->where('id', $subscription->id)->update([
                'billing_anchor_day' => $startsAt->day,
                'next_billing_at' => $nextBillingAt,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['next_billing_at']);
            $table->dropColumn(['billing_anchor_day', 'next_billing_at', 'last_billed_at']);
        });
    }
};
