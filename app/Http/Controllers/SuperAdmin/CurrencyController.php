<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\CurrencyRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-wide exchange-rate integration (Monedhat). ONE ExchangeRate-API
 * key and one daily fetch serve every hotel; hotels only choose automatic or
 * manual mode on their own Settings page.
 */
class CurrencyController extends Controller
{
    public function index(): Response
    {
        // Never ship the raw key — hint only, same rule as the tenant settings.
        $apiKey = CurrencyRates::apiKey();

        return Inertia::render('SuperAdmin/Currencies', [
            'currencies' => [
                'enabled' => (bool) PlatformSetting::get('currencies.enabled', false),
                'configured' => $apiKey !== '',
                'api_key_hint' => $apiKey !== '' ? str_repeat('•', 6).substr($apiKey, -4) : null,
                'rates' => CurrencyRates::rates(),
                'updated_at' => CurrencyRates::updatedAt(),
                'tracked' => CurrencyRates::CURRENCIES,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:100'],
            'clear_key' => ['nullable', 'boolean'],
        ]);

        PlatformSetting::set('currencies.enabled', $data['enabled'] ? '1' : '0', 'boolean');
        if ($request->boolean('clear_key')) {
            PlatformSetting::set('currencies.api_key', '', 'text');
        } elseif (trim((string) ($data['api_key'] ?? '')) !== '') {
            PlatformSetting::set('currencies.api_key', trim($data['api_key']), 'text');
        }

        return back()->with('success', 'Monedhat e platformës u ruajtën.');
    }

    /** "Rifresko tani" — inline fetch so the admin sees fresh rates instantly. */
    public function refresh(CurrencyRates $rates): RedirectResponse
    {
        if (! CurrencyRates::enabled()) {
            return back()->with('error', 'Aktivizo integrimin dhe vendos çelësin API më parë.');
        }

        try {
            $count = $rates->fetch();
        } catch (\Throwable $e) {
            return back()->with('error', 'Rifreskimi dështoi: '.$e->getMessage());
        }

        return back()->with('success', "U morën {$count} kurse për gjithë platformën.");
    }
}
