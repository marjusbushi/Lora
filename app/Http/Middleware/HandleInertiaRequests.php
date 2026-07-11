<?php

namespace App\Http\Middleware;

use App\Models\Setting;
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

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->getRoleNames()->first(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                    'is_super_admin' => $user->is_super_admin,
                ] : null,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
            ] : null,
            // Cached so the shared prop costs one cache lookup, not 6 SELECTs per request.
            // Invalidated in Setting::set().
            'settings' => Cache::rememberForever(Setting::cacheKey(), fn () => [
                'hotel_name' => Setting::get('hotel.name', 'Hotel'),
                'currency' => Setting::get('hotel.currency', 'EUR'),
                'currency_symbol' => Setting::get('financial.default_currency_symbol', '€'),
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
            ]),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
