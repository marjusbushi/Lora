<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\CurrencyRates;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrencyRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function enable(): void
    {
        Setting::set('currencies.enabled', '1', 'boolean');
        Setting::set('currencies.api_key', 'test-fx-key', 'text');
    }

    private function fakeApi(): void
    {
        Http::fake(['v6.exchangerate-api.com/*' => Http::response([
            'result' => 'success',
            'conversion_rates' => ['USD' => 1.0842, 'GBP' => 0.8461, 'ALL' => 98.7132, 'CHF' => 0.9701,
                'TRY' => 35.42, 'JPY' => 171.3, 'CAD' => 1.48, 'AUD' => 1.63, 'SEK' => 11.42, 'NOK' => 11.71,
                'MXN' => 20.1 /* untracked — must be dropped */],
        ])]);
    }

    public function test_command_is_a_no_op_when_disabled_or_without_key(): void
    {
        $this->artisan('currency:fetch-rates')->assertSuccessful(); // stray HTTP would throw
        $this->assertSame([], CurrencyRates::rates());
    }

    public function test_fetch_stores_only_the_tracked_rates(): void
    {
        $this->enable();
        $this->fakeApi();

        $this->artisan('currency:fetch-rates')->assertSuccessful();

        $rates = CurrencyRates::rates();
        $this->assertSame(98.7132, $rates['ALL']);
        $this->assertSame(1.0842, $rates['USD']);
        $this->assertArrayNotHasKey('MXN', $rates);
        $this->assertNotNull(CurrencyRates::updatedAt());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v6/test-fx-key/latest/EUR'));
    }

    public function test_rate_prefers_api_and_falls_back_to_manual_for_all(): void
    {
        Setting::set('financial.fx_all_per_eur', 97.5, 'number');
        $this->assertSame(97.5, CurrencyRates::rate('ALL')); // API off -> manual

        $this->enable();
        $this->fakeApi();
        $this->artisan('currency:fetch-rates')->assertSuccessful();
        $this->assertSame(98.7132, CurrencyRates::rate('ALL')); // API wins
        $this->assertSame(1.0, CurrencyRates::rate('EUR'));
        $this->assertNull(CurrencyRates::rate('XYZ'));
    }

    public function test_settings_save_and_inline_refresh(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->fakeApi();

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'enabled' => true, 'api_key' => 'test-fx-key', 'clear_key' => false,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(CurrencyRates::enabled());

        $this->actingAs($admin)->post(route('settings.currencies.refresh'))->assertRedirect();
        $this->assertSame(98.7132, CurrencyRates::rates()['ALL']);

        // empty key on re-save keeps the stored one
        $this->actingAs($admin)->put(route('settings.currencies'), [
            'enabled' => true, 'api_key' => '', 'clear_key' => false,
        ])->assertRedirect();
        $this->assertSame('test-fx-key', CurrencyRates::apiKey());
    }

    public function test_hotel_can_save_a_tenant_scoped_manual_all_rate(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'enabled' => false,
            'api_key' => '',
            'clear_key' => false,
            'manual_all_rate' => 93.7837,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(93.7837, CurrencyRates::rate('ALL'));

        $this->actingAs($admin)->put(route('settings.currencies'), [
            'enabled' => false,
            'api_key' => '',
            'clear_key' => false,
            'manual_all_rate' => 0,
        ])->assertSessionHasErrors('manual_all_rate');

        $this->assertSame(93.7837, CurrencyRates::rate('ALL'));
    }

    public function test_failed_api_reports_a_clean_error(): void
    {
        $this->enable();
        Http::fake(['v6.exchangerate-api.com/*' => Http::response(['result' => 'error', 'error-type' => 'invalid-key'], 200)]);

        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('settings.currencies.refresh'))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame([], CurrencyRates::rates());
    }
}
