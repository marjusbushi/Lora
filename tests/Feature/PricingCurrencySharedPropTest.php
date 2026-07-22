<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PricingCurrencySharedPropTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_shared_settings_expose_pricing_currency_when_it_differs_from_base(): void
    {
        $admin = $this->admin();

        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        Setting::set('pricing.currency', 'EUR');

        $this->actingAs($admin)
            ->get(route('rooms.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.currency', 'ALL')
                ->where('settings.pricing_currency', 'EUR')
                ->where('settings.pricing_currency_symbol', '€'));
    }

    public function test_pricing_currency_falls_back_to_base_currency_when_unset(): void
    {
        $admin = $this->admin();

        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());

        $this->actingAs($admin)
            ->get(route('rooms.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.currency', 'ALL')
                ->where('settings.pricing_currency', 'ALL')
                ->where('settings.pricing_currency_symbol', 'L'));
    }
}
