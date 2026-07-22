<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\CurrencyRates;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformCurrencyRatesTest extends TestCase
{
    use RefreshDatabase;

    private function withTenantContext(): void
    {
        app(TenantContext::class)->set(Tenant::query()->sole());
    }

    public function test_platform_rates_win_over_legacy_tenant_rates(): void
    {
        $this->withTenantContext();
        PlatformSetting::set('currencies.rates', ['USD' => 1.10, 'ALL' => 95.0], 'json');
        PlatformSetting::set('currencies.updated_at', '2026-07-22 06:00:00');
        Setting::set('currencies.rates', ['USD' => 1.99, 'ALL' => 80.0], 'json');
        Setting::set('currencies.updated_at', '2026-07-01 06:00:00');

        $this->assertSame(1.10, CurrencyRates::rate('USD'));
        $this->assertSame(95.0, CurrencyRates::rate('ALL'));
        $this->assertSame('2026-07-22 06:00:00', CurrencyRates::updatedAt());
    }

    public function test_legacy_tenant_rates_used_while_platform_is_empty(): void
    {
        $this->withTenantContext();
        Setting::set('currencies.rates', ['USD' => 1.20, 'ALL' => 92.0], 'json');
        Setting::set('currencies.updated_at', '2026-07-20 06:00:00');

        $this->assertSame(1.20, CurrencyRates::rate('USD'));
        $this->assertSame(92.0, CurrencyRates::rate('ALL'));
        $this->assertSame('2026-07-20 06:00:00', CurrencyRates::updatedAt());
    }

    public function test_manual_all_rate_is_fallback_in_automatic_mode(): void
    {
        $this->withTenantContext();
        Setting::set('financial.fx_all_per_eur', 94, 'number');

        // No platform or legacy rates at all: ALL falls back, USD is unknown.
        $this->assertSame(94.0, CurrencyRates::rate('ALL'));
        $this->assertNull(CurrencyRates::rate('USD'));

        // Once platform rates exist, they win in automatic mode.
        PlatformSetting::set('currencies.rates', ['USD' => 1.14, 'ALL' => 93.72], 'json');
        $this->assertSame(93.72, CurrencyRates::rate('ALL'));
    }

    public function test_manual_mode_pins_the_hotels_all_rate_over_platform(): void
    {
        $this->withTenantContext();
        PlatformSetting::set('currencies.rates', ['USD' => 1.14, 'ALL' => 93.72], 'json');
        Setting::set('financial.fx_all_per_eur', 97.5, 'number');
        Setting::set('currencies.mode', CurrencyRates::MODE_MANUAL);

        $this->assertSame(CurrencyRates::MODE_MANUAL, CurrencyRates::mode());
        $this->assertSame(97.5, CurrencyRates::rate('ALL'));
        // Other currencies still come from the platform in manual mode.
        $this->assertSame(1.14, CurrencyRates::rate('USD'));
        // Crossing uses the pinned ALL rate.
        $this->assertSame(round(97.5 / 1.14, 6), CurrencyRates::between('USD', 'ALL'));
    }

    public function test_manual_mode_without_a_rate_still_uses_platform(): void
    {
        $this->withTenantContext();
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');
        Setting::set('currencies.mode', CurrencyRates::MODE_MANUAL);

        // Manual mode chosen but no manual rate entered: platform still serves.
        $this->assertSame(93.72, CurrencyRates::rate('ALL'));
    }

    public function test_mode_defaults_to_automatic(): void
    {
        $this->withTenantContext();

        $this->assertSame(CurrencyRates::MODE_AUTOMATIC, CurrencyRates::mode());
    }

    public function test_enabled_and_api_key_read_the_platform_store(): void
    {
        $this->assertFalse(CurrencyRates::enabled());

        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        $this->assertFalse(CurrencyRates::enabled());

        PlatformSetting::set('currencies.api_key', 'platform-key');
        $this->assertTrue(CurrencyRates::enabled());
        $this->assertSame('platform-key', CurrencyRates::apiKey());
    }

    public function test_manual_mode_uses_per_currency_manual_rates(): void
    {
        $this->withTenantContext();
        PlatformSetting::set('currencies.rates', ['USD' => 1.14, 'ALL' => 93.72, 'GBP' => 0.85], 'json');
        Setting::set('currencies.mode', CurrencyRates::MODE_MANUAL);
        Setting::set('currencies.manual_rates', ['USD' => 1.20, 'ALL' => 97.0], 'json');

        // Entered manual rates win; a rate not yet entered falls to the platform.
        $this->assertSame(1.20, CurrencyRates::rate('USD'));
        $this->assertSame(97.0, CurrencyRates::rate('ALL'));
        $this->assertSame(0.85, CurrencyRates::rate('GBP'));
    }

    public function test_legacy_manual_all_rate_feeds_the_new_manual_rates(): void
    {
        $this->withTenantContext();
        Setting::set('financial.fx_all_per_eur', 94.5, 'number');

        $this->assertSame(94.5, CurrencyRates::manualRates()['ALL']);

        // The new store wins over the legacy field once saved.
        Setting::set('currencies.manual_rates', ['ALL' => 96.0], 'json');
        $this->assertSame(96.0, CurrencyRates::manualRates()['ALL']);
    }

    public function test_disabled_currency_has_no_rate_and_protected_ones_cannot_be_disabled(): void
    {
        $this->withTenantContext();
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        Setting::set('pricing.currency', 'EUR');
        PlatformSetting::set('currencies.rates', ['USD' => 1.14, 'ALL' => 93.72, 'JPY' => 185.9], 'json');

        // Protection is DYNAMIC from the hotel's settings: base ALL + pricing EUR.
        $this->assertEqualsCanonicalizing(['ALL', 'EUR'], CurrencyRates::protectedCurrencies());

        Setting::set('currencies.disabled', ['JPY', 'ALL'], 'json');

        // ALL is protected — the stored value is ignored; JPY is genuinely off.
        $this->assertSame(['JPY'], CurrencyRates::disabledCurrencies());
        $this->assertNull(CurrencyRates::rate('JPY'));
        $this->assertSame(93.72, CurrencyRates::rate('ALL'));
        $this->assertNotContains('JPY', CurrencyRates::enabledCurrencies());
    }

    public function test_staleness_flags_failed_or_old_fetches_only_when_enabled(): void
    {
        // Integration off: never stale.
        $this->assertFalse(CurrencyRates::isStale());

        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');

        // Enabled but never fetched: stale.
        $this->assertTrue(CurrencyRates::isStale());

        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');
        PlatformSetting::set('currencies.updated_at', now()->subHours(2)->toDateTimeString());
        $this->assertFalse(CurrencyRates::isStale());

        PlatformSetting::set('currencies.updated_at', now()->subHours(26)->toDateTimeString());
        $this->assertTrue(CurrencyRates::isStale());

        // Fresh timestamp but a recorded failure still alerts.
        PlatformSetting::set('currencies.updated_at', now()->toDateTimeString());
        CurrencyRates::recordFetchFailure('invalid-key');
        $this->assertTrue(CurrencyRates::isStale());
        $this->assertStringContainsString('invalid-key', CurrencyRates::lastError());
    }

    public function test_successful_fetch_clears_the_recorded_failure(): void
    {
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');
        CurrencyRates::recordFetchFailure('boom');
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'success',
                'conversion_rates' => ['ALL' => 93.72],
            ]),
        ]);

        app(CurrencyRates::class)->fetch();

        $this->assertSame('', CurrencyRates::lastError());
        $this->assertFalse(CurrencyRates::isStale());
    }

    public function test_failed_command_records_the_error_for_the_admin_banner(): void
    {
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response(['result' => 'error', 'error-type' => 'invalid-key']),
        ]);

        $this->artisan('currency:fetch-rates')->assertFailed();

        $this->assertNotSame('', CurrencyRates::lastError());
        $this->assertTrue(CurrencyRates::isStale());
    }

    public function test_command_is_a_noop_when_platform_integration_is_off(): void
    {
        Http::fake();

        $this->artisan('currency:fetch-rates')
            ->expectsOutputToContain('OFF')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_command_fetches_once_for_the_whole_platform(): void
    {
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'success',
                'conversion_rates' => ['USD' => 1.1406, 'ALL' => 93.7193],
            ]),
        ]);

        $this->artisan('currency:fetch-rates')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(93.7193, PlatformSetting::get('currencies.rates')['ALL']);
    }

    public function test_command_fails_loudly_on_api_error(): void
    {
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response(['result' => 'error', 'error-type' => 'invalid-key']),
        ]);

        $this->artisan('currency:fetch-rates')->assertFailed();

        $this->assertNull(PlatformSetting::get('currencies.rates'));
    }

    public function test_fetch_stores_rates_platform_wide(): void
    {
        PlatformSetting::set('currencies.api_key', 'platform-key');
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'success',
                'conversion_rates' => ['USD' => 1.1406, 'ALL' => 93.7193, 'GBP' => 0.8521, 'XXX' => 9.9],
            ]),
        ]);

        $count = app(CurrencyRates::class)->fetch();

        $this->assertSame(3, $count);
        $this->assertSame(93.7193, PlatformSetting::get('currencies.rates')['ALL']);
        $this->assertNotNull(PlatformSetting::get('currencies.updated_at'));
        // Nothing written into any tenant's settings.
        $this->assertSame(0, Setting::withoutGlobalScopes()->where('group', 'currencies')->where('key', 'rates')->count());
    }
}
