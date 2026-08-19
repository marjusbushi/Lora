<?php

namespace App\Http\Controllers;

use App\Jobs\PushRoomTypeAri;
use App\Models\PricingOffer;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use App\Services\ModuleCatalog;
use App\Services\OtaSellWindow;
use App\Services\PricingRulesVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    public function index(OtaSellWindow $sellWindow): Response
    {
        $roomTypes = RoomType::orderBy('name')->get(['id', 'name', 'base_price']);

        $seasonModels = Season::orderByDesc('priority')->orderBy('start_date')
            ->with('rates:id,season_id,room_type_id,price')
            ->get();
        $seasons = $seasonModels->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'start_date' => $s->start_date->toDateString(),
            'end_date' => $s->end_date->toDateString(),
            'priority' => $s->priority,
            'rates' => $s->rates->mapWithKeys(fn ($r) => [$r->room_type_id => (float) $r->price]),
        ]);
        $sourceYears = $seasonModels
            ->map(fn (Season $season) => $season->start_date->year)
            ->unique()
            ->sort()
            ->values();
        $defaultSourceYear = $sourceYears->last() ?? now()->year;

        return Inertia::render('Pricing/Index', [
            'roomTypes' => $roomTypes,
            'seasons' => $seasons,
            // OTA offers — the extranet campaign's compensation records.
            'offers' => PricingOffer::orderByDesc('starts_on')->get()
                ->map(fn (PricingOffer $offer) => [
                    'id' => $offer->id,
                    'name' => $offer->name,
                    'channel' => $offer->channel,
                    'discount_pct' => (float) $offer->discount_pct,
                    'starts_on' => $offer->starts_on->toDateString(),
                    'ends_on' => $offer->ends_on->toDateString(),
                    'active' => $offer->active,
                ]),
            'otaWindow' => $sellWindow->summary(),
            'seasonCopy' => [
                'source_years' => $sourceYears,
                'default_source_year' => $defaultSourceYear,
                'default_target_year' => $defaultSourceYear + 1,
            ],
            // Catalog price for the locked "Kalendari" tab (PricingTabs) — read
            // from the live catalog so a price change never leaves stale UI.
            'smartModule' => [
                'priceCents' => (int) (ModuleCatalog::module('smart_pricing')['unit_price_cents'] ?? 0),
            ],
        ]);
    }

    /**
     * OTA offers: the discount campaign is ACTIVATED in the OTA's extranet by
     * the hotel; these records only make the push compensate the price for the
     * channel and window (PricingOffer's saved event triggers the resync).
     */
    public function storeOffer(Request $request): RedirectResponse
    {
        PricingOffer::create($this->validateOffer($request));

        return back()->with('success', 'Oferta u shtua — çmimet e kanalit po ripërshtaten.');
    }

    public function updateOffer(Request $request, PricingOffer $pricingOffer): RedirectResponse
    {
        $pricingOffer->update($this->validateOffer($request));

        return back()->with('success', 'Oferta u përditësua — çmimet e kanalit po ripërshtaten.');
    }

    public function destroyOffer(PricingOffer $pricingOffer): RedirectResponse
    {
        $pricingOffer->delete();

        return back()->with('success', 'Oferta u fshi — çmimet e kanalit po rikthehen.');
    }

    /** @return array<string, mixed> */
    private function validateOffer(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'channel' => ['required', 'in:'.implode(',', PricingOffer::CHANNELS)],
            // 70% cap pairs with the 0.3 factor floor in OtaPricingPrograms.
            'discount_pct' => ['required', 'numeric', 'min:0.01', 'max:70'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    public function storeSeason(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        DB::transaction(function () use ($data) {
            $version = PricingRulesVersion::lock();
            Season::create($data);
            PricingRulesVersion::increment($version);
        }, 3);

        return back()->with('success', 'Sezoni u shtua.');
    }

    public function updateSeason(Request $request, Season $season): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        DB::transaction(function () use ($data, $season) {
            $version = PricingRulesVersion::lock();
            $lockedSeason = Season::query()->whereKey($season->id)->lockForUpdate()->firstOrFail();
            $lockedSeason->fill($data);
            $engineChanged = $lockedSeason->isDirty(['start_date', 'end_date', 'priority']);
            if ($lockedSeason->isDirty()) {
                $lockedSeason->save();
            }
            if ($engineChanged) {
                PricingRulesVersion::increment($version);
            }
        }, 3);

        return back()->with('success', 'Sezoni u perditesua.');
    }

    public function destroySeason(Season $season): RedirectResponse
    {
        DB::transaction(function () use ($season) {
            $version = PricingRulesVersion::lock();
            $lockedSeason = Season::query()->whereKey($season->id)->lockForUpdate()->firstOrFail();
            $lockedSeason->delete(); // cascades season_rates
            PricingRulesVersion::increment($version);
        }, 3);

        return back()->with('success', 'Sezoni u fshi.');
    }

    /**
     * Save the whole price matrix: base price per room type + a price per
     * (season × room type). An empty/blank season cell removes that rate so
     * the night falls back to the base price.
     */
    public function saveRates(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base' => ['array'],
            'base.*' => ['nullable', 'numeric', 'min:0'],
            'rates' => ['array'],
            'rates.*' => ['array'],
            'rates.*.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $changed = DB::transaction(function () use ($data) {
            $version = PricingRulesVersion::lock();
            $changed = false;
            $basePrices = $data['base'] ?? [];
            ksort($basePrices);
            foreach ($basePrices as $roomTypeId => $price) {
                if ($price !== null && $price !== '') {
                    $roomType = RoomType::query()->whereKey($roomTypeId)->lockForUpdate()->first();
                    $normalized = round((float) $price, 2);
                    if ($roomType && abs((float) $roomType->base_price - $normalized) > 0.009) {
                        $roomType->update(['base_price' => $normalized]);
                        $changed = true;
                    }
                }
            }

            $rates = $data['rates'] ?? [];
            ksort($rates);
            foreach ($rates as $seasonId => $byType) {
                ksort($byType);
                foreach ($byType as $roomTypeId => $price) {
                    $rate = SeasonRate::query()
                        ->where('season_id', $seasonId)
                        ->where('room_type_id', $roomTypeId)
                        ->lockForUpdate()
                        ->first();
                    if ($price === null || $price === '') {
                        if ($rate) {
                            $rate->delete();
                            $changed = true;
                        }
                    } else {
                        $normalized = round((float) $price, 2);
                        if (! $rate) {
                            SeasonRate::create([
                                'season_id' => $seasonId,
                                'room_type_id' => $roomTypeId,
                                'price' => $normalized,
                            ]);
                            $changed = true;
                        } elseif (abs((float) $rate->price - $normalized) > 0.009) {
                            $rate->update(['price' => $normalized]);
                            $changed = true;
                        }
                    }
                }
            }

            if ($changed) {
                PricingRulesVersion::increment($version);
            }

            return $changed;
        }, 3);

        // Prices changed -> re-push availability + rates to the channel manager.
        if ($changed) {
            PushRoomTypeAri::dispatchAllMapped();
        }

        return back()->with('success', 'Cmimet u ruajten.');
    }
}
