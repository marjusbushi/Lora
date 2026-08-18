<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\PlatformBillingCurrency;
use App\Services\PlatformBillingService;
use App\Services\TenantBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Monedha e FATURIMIT të platformës kundrejt monedhës OPERATIVE të hotelit.
 *
 * Bug-u që këto teste ndalojnë të rikthehet: abonimi e trashëgonte monedhën me
 * të cilën hoteli shet dhoma, ndërsa çmimet e katalogut janë euro — një hotel
 * me bazë lek e shihte €266 si "ALL 266" dhe fatura dilte 100 herë më e lirë.
 */
class PlatformBillingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const STEP = PlatformBillingCurrency::ROUNDING_STEP_CENTS;

    private function makeTenant(string $operatingCurrency): Tenant
    {
        return Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Hotel '.$operatingCurrency,
            'slug' => 'hotel-'.Str::lower($operatingCurrency).'-'.Str::random(6),
            'status' => 'active',
            'timezone' => 'Europe/Tirane',
            'currency' => $operatingCurrency,
        ]);
    }

    /** Vetëm Lora Core, me çmim të pinuar — që pritshmëritë të mos varen nga katalogu. */
    private function pinCoreOnly(Tenant $tenant, int $priceCents = 2900): void
    {
        $tenant->moduleEntitlements()->update(['enabled' => false]);
        $tenant->moduleEntitlements()
            ->where('module_code', TenantBillingService::CORE)
            ->update([
                'enabled' => true,
                'quantity' => 1,
                'unit_price_cents' => $priceCents,
                'pricing_snapshot' => json_encode([
                    'name' => 'Lora Core',
                    'description' => 'Test',
                    'billing_model' => 'flat',
                    'unit_label' => 'muaj',
                    'unit_price_cents' => $priceCents,
                ]),
            ]);
    }

    private function freshTenant(Tenant $tenant): Tenant
    {
        return $tenant->fresh()->load('subscription', 'moduleEntitlements');
    }

    private function invoice(Tenant $tenant, string $startsOn = '2026-08-01'): BillingInvoice
    {
        return app(PlatformBillingService::class)->createInvoice($this->freshTenant($tenant), [
            'period_starts_on' => $startsOn,
            'due_on' => '2026-08-15',
        ]);
    }

    public function test_a_hotel_operating_in_lek_is_still_billed_in_euro_by_default(): void
    {
        $tenant = $this->makeTenant('ALL');

        $subscription = app(TenantBillingService::class)->provision($tenant, true);

        $this->assertSame('EUR', $subscription->billing_currency);
        $this->assertSame('ALL', $tenant->currency, 'Monedha operative e hotelit mbetet e vetja.');
    }

    public function test_changing_the_hotel_operating_currency_never_moves_the_billing_currency(): void
    {
        // PIKËRISHT regresioni që shkaktoi bug-un origjinal.
        $tenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($tenant, true);

        $tenant->update(['currency' => 'ALL']);
        app(TenantBillingService::class)->update($this->freshTenant($tenant), [
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'modules' => [],
        ]);

        $this->assertSame('EUR', $tenant->fresh()->subscription->billing_currency);
    }

    public function test_summary_keeps_every_amount_in_euro_cents(): void
    {
        $tenant = $this->makeTenant('ALL');
        app(TenantBillingService::class)->provision($tenant, true);
        $this->pinCoreOnly($tenant);
        $tenant->subscription()->update(['billing_currency' => 'ALL']);
        PlatformSetting::set('currencies.rates', ['ALL' => 100.4], 'json');

        $summary = app(TenantBillingService::class)->summary($this->freshTenant($tenant));

        $this->assertSame('ALL', $summary['billing_currency']);
        $this->assertSame(2900, $summary['monthly_fixed_cents'], 'Përmbledhja mbetet euro; konvertimi ndodh vetëm te fatura.');
        $this->assertSame(['EUR', 'ALL'], $summary['billing_currency_options']);
    }

    public function test_a_euro_invoice_is_unchanged_and_carries_a_neutral_rate(): void
    {
        $tenant = $this->makeTenant('ALL');
        app(TenantBillingService::class)->provision($tenant, true);
        $this->pinCoreOnly($tenant);

        $invoice = $this->invoice($tenant);

        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame(1.0, (float) $invoice->fx_rate);
        $this->assertSame('EUR', $invoice->fx_base);
        $this->assertSame(2900, $invoice->total_cents);
        $this->assertSame(2900, $invoice->lines->sole()->amount_cents);
    }

    public function test_a_lek_invoice_converts_each_line_and_rounds_it_to_ten_units(): void
    {
        $tenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($tenant, true);
        $this->pinCoreOnly($tenant);
        $tenant->subscription()->update(['billing_currency' => 'ALL']);
        PlatformSetting::set('currencies.rates', ['ALL' => 100.4], 'json');

        $invoice = $this->invoice($tenant);

        // 2900 cent euro × 100.4 = 291 160 cent lek → 2 911.60 lek → 2 910 lek.
        $this->assertSame('ALL', $invoice->currency);
        $this->assertSame(100.4, (float) $invoice->fx_rate);
        $this->assertSame(291000, $invoice->lines->sole()->amount_cents);
        $this->assertSame(0, $invoice->lines->sole()->amount_cents % self::STEP, 'Çdo rresht rrumbullakoset në 10 njësi.');
        $this->assertSame(2900, $invoice->lines->sole()->metadata['base_amount_cents'], 'Vlera origjinale në euro mbetet e gjurmueshme.');
    }

    public function test_the_invoice_total_is_the_sum_of_the_rounded_lines(): void
    {
        // Ndryshe fatura nuk do të mblidhej me sytë e klientit.
        $tenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($tenant, true);
        $tenant->subscription()->update(['billing_currency' => 'ALL']);
        PlatformSetting::set('currencies.rates', ['ALL' => 100.4], 'json');

        $invoice = $this->invoice($tenant);

        $this->assertGreaterThan(1, $invoice->lines->count(), 'Ky rast ka kuptim vetëm me shumë rreshta.');
        $this->assertSame($invoice->lines->sum('amount_cents'), $invoice->subtotal_cents);
        $this->assertSame($invoice->subtotal_cents - $invoice->discount_cents, $invoice->total_cents);

        foreach ($invoice->lines as $line) {
            $this->assertSame(0, $line->amount_cents % self::STEP, "Rreshti {$line->module_code} s'është rrumbullakosur në 10.");
        }
    }

    public function test_a_later_rate_change_never_touches_an_invoice_already_issued(): void
    {
        $tenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($tenant, true);
        $this->pinCoreOnly($tenant);
        $tenant->subscription()->update(['billing_currency' => 'ALL']);
        PlatformSetting::set('currencies.rates', ['ALL' => 100.4], 'json');

        $invoice = $this->invoice($tenant);
        $totalWhenIssued = $invoice->total_cents;

        PlatformSetting::set('currencies.rates', ['ALL' => 130.0], 'json');

        $reloaded = BillingInvoice::query()->findOrFail($invoice->id);
        $this->assertSame(100.4, (float) $reloaded->fx_rate);
        $this->assertSame($totalWhenIssued, $reloaded->total_cents);
    }

    public function test_a_fixed_contract_rate_beats_the_daily_platform_rate(): void
    {
        $tenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($tenant, true);
        $this->pinCoreOnly($tenant);
        $tenant->subscription()->update(['billing_currency' => 'ALL', 'fx_rate_override' => 90.0]);
        PlatformSetting::set('currencies.rates', ['ALL' => 130.0], 'json');

        $invoice = $this->invoice($tenant);

        $this->assertSame(90.0, (float) $invoice->fx_rate);
        $this->assertSame(261000, $invoice->lines->sole()->amount_cents); // 2900 × 90 = 261 000
    }

    public function test_the_hotel_cannot_decide_what_it_pays_lora_through_its_own_manual_rates(): void
    {
        // KRITIK. CurrencyRates::rate() lexon cilësimet e hotelit; faturimi i
        // platformës nuk guxon ta prekë atë rrugë kurrë.
        $tenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($tenant, true);
        $this->pinCoreOnly($tenant);
        $tenant->subscription()->update(['billing_currency' => 'ALL']);
        PlatformSetting::set('currencies.rates', ['ALL' => 100.4], 'json');

        // Hoteli shpall te cilësimet e VETA se 1 € = 1 lek.
        app(TenantContext::class)->run($tenant, function () {
            Setting::set('currencies.mode', 'manual');
            Setting::set('currencies.manual_rates', ['ALL' => 1.0], 'json');
        });

        $invoice = $this->invoice($tenant);

        $this->assertSame(100.4, (float) $invoice->fx_rate, 'Kursi i hotelit nuk hyn dot te fatura e platformës.');
        $this->assertSame(291000, $invoice->lines->sole()->amount_cents);
    }

    public function test_a_missing_rate_refuses_the_invoice_instead_of_billing_at_one_to_one(): void
    {
        $tenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($tenant, true);
        $this->pinCoreOnly($tenant);
        $tenant->subscription()->update(['billing_currency' => 'ALL']);
        PlatformSetting::set('currencies.rates', ['USD' => 1.08], 'json');

        $this->expectException(ValidationException::class);

        try {
            $this->invoice($tenant);
        } finally {
            $this->assertSame(0, BillingInvoice::query()->where('tenant_id', $tenant->id)->count(),
                'Asnjë faturë s\'duhet të mbetet pas refuzimit.');
        }
    }

    public function test_an_unlisted_billing_currency_is_refused(): void
    {
        $tenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($tenant, true);

        $this->expectException(ValidationException::class);

        app(TenantBillingService::class)->update($this->freshTenant($tenant), [
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'billing_currency' => 'XAU',
            'modules' => [],
        ]);
    }

    public function test_the_migration_leaves_every_existing_subscription_and_invoice_on_euro(): void
    {
        // Migrimi shton vetëm kolona me default 'EUR' — asnjë rresht nuk preket,
        // ndaj çdo abonim ekzistues bëhet i saktë në çastin e vendosjes.
        $this->assertTrue(Schema::hasColumn('tenant_subscriptions', 'billing_currency'));
        $this->assertTrue(Schema::hasColumn('tenant_subscriptions', 'fx_rate_override'));
        $this->assertTrue(Schema::hasColumn('billing_invoices', 'fx_rate'));
        $this->assertTrue(Schema::hasColumn('billing_invoices', 'fx_base'));

        foreach (TenantSubscription::query()->get() as $subscription) {
            $this->assertSame('EUR', $subscription->billing_currency);
        }
    }
}
