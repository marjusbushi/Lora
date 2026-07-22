<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Setting;
use App\Models\User;
use App\Services\CurrencyRates;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tenant-facing currency contract: the hotel only chooses automatic/manual
 * mode and its manual ALL rate — the rates themselves are platform-wide
 * (see PlatformCurrencyRatesTest / PlatformCurrencyAdminTest).
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

    public function test_hotel_saves_manual_mode_and_rate(): void
    {
        $admin = $this->admin();
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'mode' => 'manual',
            'manual_all_rate' => 97.5,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('manual', CurrencyRates::mode());
        // Manual mode pins the hotel's rate over the platform table.
        $this->assertSame(97.5, CurrencyRates::rate('ALL'));
    }

    public function test_switching_back_to_automatic_restores_platform_rates(): void
    {
        $admin = $this->admin();
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');
        Setting::set('currencies.mode', 'manual');
        Setting::set('financial.fx_all_per_eur', 97.5, 'number');

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'mode' => 'automatic',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('automatic', CurrencyRates::mode());
        $this->assertSame(93.72, CurrencyRates::rate('ALL'));
    }

    public function test_manual_rate_of_zero_is_rejected(): void
    {
        $admin = $this->admin();
        Setting::set('financial.fx_all_per_eur', 93.7837, 'number');

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'mode' => 'manual',
            'manual_all_rate' => 0,
        ])->assertSessionHasErrors('manual_all_rate');

        $this->assertSame(93.7837, (float) Setting::get('financial.fx_all_per_eur'));
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'mode' => 'platform',
        ])->assertSessionHasErrors('mode');
    }

    public function test_settings_page_shares_the_platform_rates_read_only(): void
    {
        $admin = $this->admin();
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72, 'USD' => 1.14], 'json');

        $response = $this->actingAs($admin)->get(route('settings.index'));

        $response->assertOk();
        $currencies = $response->viewData('page')['props']['settings']['currencies'];
        $this->assertSame('automatic', $currencies['mode']);
        $this->assertTrue($currencies['platform_enabled']);
        $this->assertSame(93.72, $currencies['rates']['ALL']);
        $this->assertArrayNotHasKey('api_key_hint', $currencies);
        $this->assertArrayNotHasKey('configured', $currencies);
    }
}
