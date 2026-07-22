<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformCurrencyAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'lora.control_panel_url' => 'https://admin.lorapms.test',
            'lora.control_panel_hosts' => ['admin.lorapms.test'],
            'lora.dedicated_control_panel_hosts' => ['admin.lorapms.test'],
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_super_admin_sees_platform_currencies_with_key_hint_only(): void
    {
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'secret-key-82d3');
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');

        $this->actingAs($this->superAdmin())
            ->get('https://admin.lorapms.test/super-admin/currencies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/Currencies')
                ->where('currencies.enabled', true)
                ->where('currencies.configured', true)
                ->where('currencies.api_key_hint', '••••••82d3')
                ->where('currencies.rates.ALL', 93.72)
                ->missing('currencies.api_key'));
    }

    public function test_super_admin_sees_the_stale_rates_alert(): void
    {
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');
        PlatformSetting::set('currencies.updated_at', now()->subHours(30)->toDateTimeString());

        $this->actingAs($this->superAdmin())
            ->get('https://admin.lorapms.test/super-admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('platformAlerts.currency_rates.stale', true));
    }

    public function test_fresh_rates_do_not_raise_the_alert(): void
    {
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');
        PlatformSetting::set('currencies.rates', ['ALL' => 93.72], 'json');
        PlatformSetting::set('currencies.updated_at', now()->subHours(2)->toDateTimeString());

        $this->actingAs($this->superAdmin())
            ->get('https://admin.lorapms.test/super-admin')
            ->assertInertia(fn (Assert $page) => $page
                ->where('platformAlerts.currency_rates.stale', false));
    }

    public function test_hotel_staff_get_no_platform_alerts(): void
    {
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');

        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('platformAlerts', null));
    }

    public function test_regular_user_cannot_open_platform_currencies(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->get('https://admin.lorapms.test/super-admin/currencies')
            ->assertForbidden();
    }

    public function test_update_writes_the_platform_store(): void
    {
        $this->actingAs($this->superAdmin())
            ->from('https://admin.lorapms.test/super-admin/currencies')
            ->put('https://admin.lorapms.test/super-admin/currencies', [
                'enabled' => true,
                'api_key' => 'new-platform-key',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue((bool) PlatformSetting::get('currencies.enabled'));
        $this->assertSame('new-platform-key', PlatformSetting::get('currencies.api_key'));
    }

    public function test_refresh_requires_an_enabled_integration(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin())
            ->from('https://admin.lorapms.test/super-admin/currencies')
            ->post('https://admin.lorapms.test/super-admin/currencies/refresh')
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_refresh_fetches_and_stores_platform_rates(): void
    {
        PlatformSetting::set('currencies.enabled', '1', 'boolean');
        PlatformSetting::set('currencies.api_key', 'platform-key');
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'success',
                'conversion_rates' => ['USD' => 1.14, 'ALL' => 93.72],
            ]),
        ]);

        $this->actingAs($this->superAdmin())
            ->from('https://admin.lorapms.test/super-admin/currencies')
            ->post('https://admin.lorapms.test/super-admin/currencies/refresh')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(93.72, PlatformSetting::get('currencies.rates')['ALL']);
    }
}
