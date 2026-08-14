<?php

namespace App\Http\Controllers;

use App\Http\Requests\Beach\GenerateBeachUnitsRequest;
use App\Http\Requests\Beach\StoreBeachZoneRequest;
use App\Http\Requests\Beach\UpdateBeachZoneRequest;
use App\Models\BeachReservation;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\Setting;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BeachSetupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Beach/Setup', [
            'zones' => BeachZone::query()
                ->with(['units' => fn ($query) => $query->orderBy('sort_order')->orderBy('number')])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'settings' => [
                'booking_window_days' => (int) Setting::get('beach.booking_window_days', 10),
                'season_start' => (string) Setting::get('beach.season_start', ''),
                'season_end' => (string) Setting::get('beach.season_end', ''),
            ],
        ]);
    }

    public function storeZone(StoreBeachZoneRequest $request): RedirectResponse
    {
        BeachZone::create($request->validated());

        return back()->with('success', 'Zona e plazhit u krijua.');
    }

    public function updateZone(UpdateBeachZoneRequest $request, BeachZone $zone): RedirectResponse
    {
        $zone->update($request->validated());

        return back()->with('success', 'Zona e plazhit u përditësua.');
    }

    public function destroyZone(BeachZone $zone): RedirectResponse
    {
        $unitIds = $zone->units()->pluck('id');

        $this->guardAgainstReservationHistory(
            $unitIds->all(),
            'Zona ka rezervime aktive — anulloji ose prit të mbarojnë para se ta fshish.',
            'Zona ka historik rezervimesh — çaktivizoje në vend që ta fshish, që historiku të ruhet.',
        );

        DB::transaction(function () use ($zone) {
            $zone->units()->delete();
            $zone->delete();
        });

        return back()->with('success', 'Zona e plazhit u fshi.');
    }

    public function generateUnits(GenerateBeachUnitsRequest $request, BeachZone $zone): RedirectResponse
    {
        $count = (int) $request->validated('count');

        // Numrat janë unikë per-tenant (jo per-zonë), ndaj vazhdojmë nga numri
        // më i lartë numerik i GJITHË plazhit që të mos ketë përplasje.
        $start = $request->filled('start_number')
            ? (int) $request->validated('start_number')
            : (int) BeachUnit::query()
                ->pluck('number')
                ->filter(fn (string $number) => ctype_digit($number))
                ->map(fn (string $number) => (int) $number)
                ->max() + 1;
        $start = max($start, 1);

        $numbers = collect(range($start, $start + $count - 1))->map(fn (int $n) => (string) $n);

        $taken = BeachUnit::query()->whereIn('number', $numbers)->pluck('number');
        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages([
                'count' => 'Këta numra çadrash ekzistojnë tashmë: '.$taken->implode(', ').'. Zgjidh një numër tjetër fillestar.',
            ]);
        }

        DB::transaction(function () use ($zone, $numbers) {
            foreach ($numbers as $number) {
                $zone->units()->create([
                    'number' => $number,
                    'sort_order' => (int) $number,
                ]);
            }
        });

        return back()->with('success', "U krijuan {$count} çadra (nr. {$numbers->first()}–{$numbers->last()}).");
    }

    public function updateUnit(Request $request, BeachUnit $unit): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:10', TenantRule::unique('beach_units', 'number')->ignore($unit->id)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        $unit->update($data);

        return back()->with('success', "Çadra {$unit->number} u përditësua.");
    }

    public function destroyUnit(BeachUnit $unit): RedirectResponse
    {
        $this->guardAgainstReservationHistory(
            [$unit->id],
            "Çadra {$unit->number} ka rezervime aktive — anulloji para se ta fshish.",
            "Çadra {$unit->number} ka historik rezervimesh — çaktivizoje në vend që ta fshish.",
        );

        $unit->delete();

        return back()->with('success', 'Çadra u fshi.');
    }

    /**
     * @param  list<int>  $unitIds
     */
    private function guardAgainstReservationHistory(array $unitIds, string $activeMessage, string $historyMessage): void
    {
        if ($unitIds === []) {
            return;
        }

        $reservations = BeachReservation::query()->whereIn('beach_unit_id', $unitIds);

        if ((clone $reservations)->where('status', '!=', BeachReservation::STATUS_CANCELLED)->exists()) {
            throw ValidationException::withMessages(['zone' => $activeMessage]);
        }

        // FK-ja restrictOnDelete do ta bllokonte gjithsesi — po e kthejmë si
        // mesazh miqësor në vend të një gabimi 500 nga databaza.
        if ($reservations->exists()) {
            throw ValidationException::withMessages(['zone' => $historyMessage]);
        }
    }
}
