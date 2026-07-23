<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrencyRates;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tenant-facing currency contract: mode (automatic read-only / manual with a
 * required rate per enabled currency) plus per-currency disabling with the
 * base and pricing currencies protected dynamically. The rates themselves are
 * platform-wide (see PlatformCurrencyRatesTest / PlatformCurrencyAdminTest).
 */
class CurrencyRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** All tracked currencies except the given ones — used as the disabled list. */
    private function allExcept(array $keep): array
    {
        return array_values(array_diff(CurrencyRates::CURRENCIES, $keep));
    }

    public function test_manual_mode_requires_a_rate_for_every_enabled_currency(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'mode' => 'manual',
            'disabled' => $this->allExcept(['ALL', 'USD']),
            'manual_rates' => ['ALL' => 97.5],
        ])->assertSessionHasErrors('manual_rates');

        $this->assertSame('automatic', CurrencyRates::mode());
    }

    public function test_manual_mode_saves_when_every_enabled_currency_has_a_rate(): void
    {
        $admin = $this->admin();
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72, 'USD' => 1.14], 'json');

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'mode' => 'manual',
            'disabled' => $this->allExcept(['ALL', 'USD']),
            'manual_rates' => ['ALL' => 97.5, 'USD' => 1.20],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('manual', CurrencyRates::mode());
        $this->assertSame(97.5, CurrencyRates::rate('ALL'));
        $this->assertSame(1.20, CurrencyRates::rate('USD'));
        // A disabled currency has no rate at all.
        $this->assertNull(CurrencyRates::rate('JPY'));
        // Legacy single-ALL field stays coherent for old readers.
        $this->assertSame(97.5, (float) Setting::get('financial.fx_all_per_eur'));
    }

    public function test_zero_or_negative_manual_rates_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'mode' => 'manual',
            'disabled' => $this->allExcept(['ALL']),
            'manual_rates' => ['ALL' => 0],
        ])->assertSessionHasErrors('manual_rates.ALL');
    }

    public function test_switching_back_to_automatic_restores_platform_rates(): void
    {
        $admin = $this->admin();
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');
        Setting::set('currencies.mode', 'manual');
        Setting::set('currencies.manual_rates', ['ALL' => 97.5], 'json');

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'mode' => 'automatic',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('automatic', CurrencyRates::mode());
        $this->assertSame(93.72, CurrencyRates::rate('ALL'));
    }

    public function test_base_and_pricing_currencies_cannot_be_disabled(): void
    {
        $admin = $this->admin();
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        Setting::set('pricing.currency', 'EUR');

        // ALL is the base currency here — dynamically protected.
        $this->actingAs($admin)->put(route('settings.currencies'), [
            'mode' => 'automatic',
            'disabled' => ['ALL'],
        ])->assertSessionHasErrors('disabled');

        $this->assertSame([], CurrencyRates::disabledCurrencies());
    }

    public function test_a_disabled_currency_cannot_become_the_pricing_currency(): void
    {
        $admin = $this->admin();
        Setting::set('currencies.disabled', ['ALL'], 'json');

        $this->actingAs($admin)->put(route('settings.hotel'), [
            'name' => 'Hotel Test',
            'timezone' => 'Europe/Tirane',
            'currency' => 'EUR',
            'pricing_currency' => 'ALL',
            'check_in_time' => '14:00',
            'check_out_time' => '11:00',
        ])->assertSessionHasErrors('pricing_currency');
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'mode' => 'platform',
        ])->assertSessionHasErrors('mode');
    }

    public function test_settings_page_shares_the_new_currency_contract(): void
    {
        $admin = $this->admin();
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72, 'USD' => 1.14], 'json');
        Setting::set('currencies.disabled', ['JPY'], 'json');

        $response = $this->actingAs($admin)->get(route('settings.index'));

        $response->assertOk();
        $currencies = $response->viewData('page')['props']['settings']['currencies'];
        $this->assertSame('automatic', $currencies['mode']);
        $this->assertTrue($currencies['platform_enabled']);
        $this->assertSame(93.72, $currencies['rates']['ALL']);
        $this->assertSame(['JPY'], $currencies['disabled']);
        $this->assertContains('EUR', $currencies['protected']);
        $this->assertArrayNotHasKey('api_key_hint', $currencies);
    }
}
