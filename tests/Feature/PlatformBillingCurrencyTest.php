<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
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

    public function test_platform_kpis_never_add_lek_cents_onto_euro_cents(): void
    {
        // Gjetje e rishikimit (P1): një faturë prej €29 e ruajtur si 291 000 cent
        // lekë do të shtonte €2 910 te kartat e të ardhurave po të mblidhej bruto.
        $euroTenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($euroTenant, true);
        $this->pinCoreOnly($euroTenant);
        $euroInvoice = $this->invoice($euroTenant);
        $euroInvoice->update(['status' => 'open']);

        $lekTenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($lekTenant, true);
        $this->pinCoreOnly($lekTenant);
        $lekTenant->subscription()->update(['billing_currency' => 'ALL']);
        PlatformSetting::set('currencies.rates', ['ALL' => 100.4], 'json');
        $lekInvoice = $this->invoice($lekTenant);
        $lekInvoice->update(['status' => 'open']);

        $this->assertSame(2900, $euroInvoice->total_cents);
        $this->assertSame(291000, $lekInvoice->total_cents);

        $stats = app(PlatformBillingService::class)->invoiceStatsInBase();

        // 2900 (euro) + 291000/100.4 ≈ 2900 → rreth €58, jo €2 939.
        $this->assertSame('EUR', $stats['currency']);
        $this->assertEqualsWithDelta(5800, $stats['open_cents'], 20,
            'KPI-ja duhet të jetë ~€58, jo shuma bruto e centëve të përzier.');
        $this->assertLessThan(10000, $stats['open_cents']);
    }

    public function test_payment_kpis_are_normalised_through_the_invoice_rate(): void
    {
        $tenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($tenant, true);
        $this->pinCoreOnly($tenant);
        $tenant->subscription()->update(['billing_currency' => 'ALL']);
        PlatformSetting::set('currencies.rates', ['ALL' => 100.4], 'json');

        $invoice = $this->invoice($tenant);
        $invoice->update(['status' => 'open']);
        $admin = User::factory()->create(['is_super_admin' => true]);

        // 2 910 lek = i gjithë bilanci (291 000 cent).
        app(PlatformBillingService::class)->registerManualPayment($invoice, [
            'amount' => 2910,
            'method' => 'bank',
            'paid_at' => now(),
        ], $admin);

        $stats = app(PlatformBillingService::class)->paymentStatsInBase();

        $this->assertSame('EUR', $stats['currency']);
        $this->assertEqualsWithDelta(2900, $stats['month_cents'], 20, 'Pagesa prej 2 910 lek është ~€29, jo €2 910.');
        $this->assertSame($stats['month_cents'], $stats['manual_cents']);
    }

    public function test_publishing_a_stale_draft_refreezes_the_rate_at_issuance(): void
    {
        // Gjetje e rishikimit (P2): drafti mund të rrijë me ditë; dokumenti nuk
        // guxon të mbajë datë lëshimi të sotme me kursin e ditës kur u shkrua.
        $tenant = $this->makeTenant('EUR');
        app(TenantBillingService::class)->provision($tenant, true);
        $this->pinCoreOnly($tenant);
        $tenant->subscription()->update(['billing_currency' => 'ALL']);
        PlatformSetting::set('currencies.rates', ['ALL' => 100.4], 'json');

        $draft = $this->invoice($tenant);
        $this->assertSame('draft', $draft->status);
        $this->assertSame(291000, $draft->total_cents);

        PlatformSetting::set('currencies.rates', ['ALL' => 130.0], 'json');
        app(PlatformBillingService::class)->publish($draft);

        $published = BillingInvoice::query()->with('lines')->findOrFail($draft->id);
        $this->assertSame('open', $published->status);
        $this->assertNotNull($published->issued_at);
        $this->assertSame(130.0, (float) $published->fx_rate);
        // Rindërtuar nga vlera bazë 2900, JO nga 291 000 e konvertuar sërish.
        $this->assertSame(377000, $published->total_cents);
        $this->assertSame(377000, $published->lines->sole()->amount_cents);
        $this->assertSame(2900, $published->lines->sole()->metadata['base_amount_cents']);
    }

    public function test_publishing_a_euro_draft_leaves_its_amounts_untouched(): void
    {
        $tenant = $this->makeTenant('ALL');
        app(TenantBillingService::class)->provision($tenant, true);
        $this->pinCoreOnly($tenant);

        $draft = $this->invoice($tenant);
        app(PlatformBillingService::class)->publish($draft);

        $published = BillingInvoice::query()->with('lines')->findOrFail($draft->id);
        $this->assertSame('open', $published->status);
        $this->assertSame(1.0, (float) $published->fx_rate);
        $this->assertSame(2900, $published->total_cents);
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
