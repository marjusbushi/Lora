<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CatalogPriceOverride;
use App\Services\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Katalogu i çmimeve të moduleve — editim platformë-global nga super-admini.
 * Struktura (emra/përshkrime/billing_model) mbetet në config; këtu editohen
 * vetëm numrat, si override. Klientët ekzistues mbajnë pricing_snapshot-in
 * e tyre — katalogu prek vetëm aktivizimet e reja.
 */
class CatalogController extends Controller
{
    public function index(): Response
    {
        $config = config('lora_modules.modules', []);
        $overrides = CatalogPriceOverride::query()->with('updatedBy:id,name')->get()->keyBy('module_code');
        $merged = ModuleCatalog::modules();

        $modules = collect($config)->map(function (array $definition, string $code) use ($overrides, $merged) {
            $override = $overrides->get($code);

            return [
                'code' => $code,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'billing_model' => $definition['billing_model'],
                'unit_label' => $definition['unit_label'],
                // Vlerat AKTIVE (config + override) — këto edito UI-ja.
                'active' => collect(ModuleCatalog::PRICE_FIELDS)
                    ->mapWithKeys(fn (string $field) => [$field => $merged[$code][$field] ?? null])
                    ->all(),
                // Baza e config-ut — shfaqet gri + "Rikthe në bazë".
                'defaults' => collect(ModuleCatalog::PRICE_FIELDS)
                    ->mapWithKeys(fn (string $field) => [$field => $definition[$field] ?? null])
                    ->all(),
                'has_override' => (bool) $override,
                'calculator_active' => (bool) ($merged[$code]['calculator_default'] ?? false),
                'calculator_base' => (bool) ($definition['calculator_default'] ?? false),
                'updated_by' => $override?->updatedBy?->name,
                'updated_at' => $override?->updated_at?->toIso8601String(),
            ];
        })->values();

        return Inertia::render('SuperAdmin/Catalog', ['modules' => $modules]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validCodes = array_keys(config('lora_modules.modules', []));

        $data = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*.code' => ['required', 'string', Rule::in($validCodes)],
            'modules.*.unit_price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'modules.*.first_unit_price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'modules.*.excess_unit_price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'modules.*.tier_limit' => ['nullable', 'integer', 'min:1', 'max:65000'],
            'modules.*.percentage_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'modules.*.calculator_default' => ['required', 'boolean'],
        ]);

        $config = config('lora_modules.modules', []);

        DB::transaction(function () use ($data, $config, $request) {
            foreach ($data['modules'] as $entry) {
                $code = $entry['code'];
                $defaults = $config[$code];

                // Vlerë e barabartë me config-un = s'ka override (mbetet NULL),
                // që "baza" të ndjekë config-un edhe kur ai ndryshon në kod.
                $override = [];
                foreach (ModuleCatalog::PRICE_FIELDS as $field) {
                    $value = $entry[$field] ?? null;
                    $override[$field] = ($value === null || (int) $value === (int) ($defaults[$field] ?? PHP_INT_MIN))
                        ? null
                        : (int) $value;
                }

                // Flag-u i kalkulatorit: barazi me config = NULL (baza ndjek kodin).
                $calculator = (bool) $entry['calculator_default'];
                $override['calculator_default_on'] = $calculator === (bool) ($defaults['calculator_default'] ?? false)
                    ? null
                    : $calculator;

                if (collect($override)->every(fn ($value) => $value === null)) {
                    CatalogPriceOverride::query()->where('module_code', $code)->delete();
                    continue;
                }

                CatalogPriceOverride::query()->updateOrCreate(
                    ['module_code' => $code],
                    $override + ['updated_by' => $request->user()->id],
                );
            }
        });

        ModuleCatalog::flush();

        return back()->with('success', __('Katalogu i çmimeve u përditësua.'));
    }
}
