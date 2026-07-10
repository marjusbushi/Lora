<?php

namespace App\Http\Controllers;

use App\Jobs\PushRoomTypeAri;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use App\Services\PricingRulesVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    public function index(): Response
    {
        $roomTypes = RoomType::orderBy('name')->get(['id', 'name', 'base_price']);

        $seasons = Season::orderByDesc('priority')->orderBy('start_date')
            ->with('rates:id,season_id,room_type_id,price')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'start_date' => $s->start_date->toDateString(),
                'end_date' => $s->end_date->toDateString(),
                'priority' => $s->priority,
                'rates' => $s->rates->mapWithKeys(fn ($r) => [$r->room_type_id => (float) $r->price]),
            ]);

        return Inertia::render('Pricing/Index', [
            'roomTypes' => $roomTypes,
            'seasons' => $seasons,
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
