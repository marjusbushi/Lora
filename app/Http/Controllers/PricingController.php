<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
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

        Season::create($data);

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

        $season->update($data);

        return back()->with('success', 'Sezoni u perditesua.');
    }

    public function destroySeason(Season $season): RedirectResponse
    {
        $season->delete(); // cascades season_rates

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

        DB::transaction(function () use ($data) {
            foreach (($data['base'] ?? []) as $roomTypeId => $price) {
                if ($price !== null && $price !== '') {
                    RoomType::where('id', $roomTypeId)->update(['base_price' => $price]);
                }
            }

            foreach (($data['rates'] ?? []) as $seasonId => $byType) {
                foreach ($byType as $roomTypeId => $price) {
                    if ($price === null || $price === '') {
                        SeasonRate::where('season_id', $seasonId)->where('room_type_id', $roomTypeId)->delete();
                    } else {
                        SeasonRate::updateOrCreate(
                            ['season_id' => $seasonId, 'room_type_id' => $roomTypeId],
                            ['price' => $price]
                        );
                    }
                }
            }
        });

        return back()->with('success', 'Cmimet u ruajten.');
    }
}
