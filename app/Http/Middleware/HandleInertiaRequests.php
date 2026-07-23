<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\BaseCurrency;
use App\Services\CurrencyRates;
use App\Services\PricingCurrency;
use App\Services\TenantBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = app(TenantContext::class)->tenant();

        // Spatie caches the roles/permissions relations on the model instance.
        // If the same instance survives a tenant (team) switch — long-running
        // runtimes, queued context, tests — the payload would leak the OLD
        // hotel's role. Always read them fresh for the CURRENT tenant.
        $user?->unsetRelation('roles')->unsetRelation('permissions');
        $billingAccess = $tenant ? app(TenantBillingService::class)->accessSnapshot($tenant) : null;
        $settings = $tenant
            ? Cache::rememberForever(Setting::cacheKey(), function () use ($tenant) {
                // One settings read, reused for code and symbol — the guests-page
                // query budget (GuestDirectoryTest) counts every query here.
                $pricingCurrency = PricingCurrency::code();

                return [
                    'hotel_name' => Setting::get('hotel.name', 'Hotel'),
                    'currency' => $tenant->currency,
                    'currency_symbol' => BaseCurrency::symbol(),
                    // Commercial currency — what room rates/reservation totals actually mean.
                    'pricing_currency' => $pricingCurrency,
                    'pricing_currency_symbol' => BaseCurrency::symbol($pricingCurrency),
                    'tax_rate' => Setting::get('financial.tax_rate', 20),
                    'check_in_time' => Setting::get('hotel.check_in_time', '14:00'),
                    'check_out_time' => Setting::get('hotel.check_out_time', '11:00'),
                    // Public-website branding & contact (managed in Settings → "Faqja Web")
                    'logo' => Setting::get('hotel.logo'),
                    'address' => Setting::get('hotel.address'),
                    'phone' => Setting::get('hotel.phone'),
                    'email' => Setting::get('hotel.email'),
                    'instagram' => Setting::get('hotel.instagram'),
                    'facebook' => Setting::get('hotel.facebook'),
                    'maps_url' => Setting::get('hotel.maps_url'),
                ];
            })
            : [
                'hotel_name' => 'Lora PMS',
                'currency' => 'EUR',
                'currency_symbol' => '€',
                'pricing_currency' => 'EUR',
                'pricing_currency_symbol' => '€',
                'tax_rate' => 20,
                'check_in_time' => '14:00',
                'check_out_time' => '11:00',
                'logo' => null,
                'address' => null,
                'phone' => null,
                'email' => null,
                'instagram' => null,
                'facebook' => null,
                'maps_url' => null,
            ];

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'role' => $tenant ? $user->getRoleNames()->first() : null,
                    'permissions' => $tenant ? $user->getAllPermissions()->pluck('name') : [],
                    'is_super_admin' => $user->is_super_admin,
                ] : null,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                // Transitional compatibility for production views still reading this prop.
                'addons' => $tenant->addons(),
            ] : null,
            'subscription' => $billingAccess ? [
                'status' => $billingAccess['status'],
                'billing_cycle' => $billingAccess['billing_cycle'],
                'current_period_ends_at' => $billingAccess['current_period_ends_at'],
            ] : null,
            'modules' => $billingAccess['modules'] ?? [],
            // Platform health — super-admin only, so hotel staff requests pay
            // zero extra queries (also keeps the guests-page query budget).
            'platformAlerts' => $user?->is_super_admin ? [
                'currency_rates' => [
                    'stale' => CurrencyRates::isStale(),
                    'updated_at' => CurrencyRates::updatedAt(),
                    'last_error' => CurrencyRates::lastError(),
                ],
            ] : null,
            // Cached so the shared prop costs one cache lookup, not 6 SELECTs per request.
            // Invalidated in Setting::set().
            'settings' => $settings,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
