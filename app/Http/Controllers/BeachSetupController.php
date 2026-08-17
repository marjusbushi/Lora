<?php

namespace App\Http\Controllers;

use App\Http\Requests\Beach\GenerateBeachUnitsRequest;
use App\Http\Requests\Beach\SaveBeachSeasonRequest;
use App\Http\Requests\Beach\StoreBeachZoneRequest;
use App\Http\Requests\Beach\UpdateBeachZoneRequest;
use App\Models\BeachReservation;
use App\Models\BeachSeason;
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
            'seasons' => BeachSeason::query()
                ->with('prices')
                ->orderBy('start_date')
                ->get(),
            'settings' => [
                'booking_window_days' => (int) Setting::get('beach.booking_window_days', 10),
                'season_start' => (string) Setting::get('beach.season_start', ''),
                'season_end' => (string) Setting::get('beach.season_end', ''),
            ],
        ]);
    }

    public function storeSeason(SaveBeachSeasonRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $this->assertNoSeasonOverlapLocked($request->input('start_date'), $request->input('end_date'));

            $season = BeachSeason::create($request->safe()->only(['name', 'start_date', 'end_date']));
            $this->syncSeasonPrices($season, $request->validated('prices') ?? []);
        });

        return back()->with('success', 'Sezoni i çmimeve u krijua.');
    }

    public function updateSeason(SaveBeachSeasonRequest $request, BeachSeason $season): RedirectResponse
    {
        DB::transaction(function () use ($request, $season) {
            $this->assertNoSeasonOverlapLocked($request->input('start_date'), $request->input('end_date'), $season->id);

            $season->update($request->safe()->only(['name', 'start_date', 'end_date']));
            $this->syncSeasonPrices($season, $request->validated('prices') ?? []);
        });

        return back()->with('success', 'Sezoni i çmimeve u përditësua.');
    }

    /**
     * Ri-verifikon mbivendosjen NËN kyçje: validimi i FormRequest-it vrapon PARA
     * transaksionit, prandaj dy shkrime konkurruese mund ta kalonin të dy dhe të
     * fusnin sezone të mbivendosura (indeksi i intervalit s'është unik). Kyçja
     * mbi rreshtin e tenant-it i serializon mutacionet e sezoneve per tenant —
     * funksionon edhe kur tabela e sezoneve është ende bosh.
     */
    private function assertNoSeasonOverlapLocked(string $start, string $end, ?int $excludeId = null): void
    {
        // KUJDES: where('id', …) — whereKey te query-builder-i bazë bëhet WHERE `key`
        // (kolonë inekzistente → 500 në MySQL; sqlite e fsheh si string-literal).
        DB::table('tenants')
            ->where('id', app(\App\Tenancy\TenantContext::class)->tenant()->id)
            ->lockForUpdate()
            ->first();

        $overlap = BeachSeason::query()
            ->when($excludeId, fn ($query) => $query->whereKeyNot($excludeId))
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->first();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_date' => "Datat mbivendosen me sezonin \"{$overlap->name}\" — çdo ditë i përket vetëm një sezoni.",
            ]);
        }
    }

    public function destroySeason(BeachSeason $season): RedirectResponse
    {
        // Çmimet e sezonit fshihen me cascade; rezervimet e bëra mbajnë
        // total_amount të ngrirë — historia s'preket.
        $season->delete();

        return back()->with('success', 'Sezoni u fshi — zonat kthehen te çmimi bazë.');
    }

    /**
     * Ruajtja e matricës me NJË kërkesë (si pricing.rates.save i dhomave):
     * base per zonë + rates per sezon per zonë. Id-të e panjohura (tenant
     * tjetër ose të fshira) kapërcehen — modelet e skopuara i filtrojnë.
     */
    public function saveSeasonRates(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base' => ['nullable', 'array'],
            'base.*' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'rates' => ['nullable', 'array'],
            'rates.*' => ['nullable', 'array'],
            'rates.*.*' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        DB::transaction(function () use ($data) {
            $zones = BeachZone::query()->get()->keyBy('id');
            foreach (($data['base'] ?? []) as $zoneId => $price) {
                // Bazë bosh s'do të thotë zero — thjesht s'preket.
                if (! isset($zones[(int) $zoneId]) || $price === null || $price === '') {
                    continue;
                }
                $zones[(int) $zoneId]->update(['price_per_day' => (float) $price]);
            }

            $seasons = BeachSeason::query()->get()->keyBy('id');
            foreach (($data['rates'] ?? []) as $seasonId => $zonePrices) {
                if (! isset($seasons[(int) $seasonId]) || ! is_array($zonePrices)) {
                    continue;
                }
                $this->syncSeasonPrices($seasons[(int) $seasonId], $zonePrices);
            }
        });

        return back()->with('success', 'Çmimet e plazhit u ruajtën.');
    }

    /**
     * Vlerë e mbushur = çmim sezonal për zonën; bosh/null = zona bie te çmimi
     * bazë (rreshti hiqet fare, s'ruajmë zero fantazmë).
     *
     * @param  array<int|string, mixed>  $prices
     */
    private function syncSeasonPrices(BeachSeason $season, array $prices): void
    {
        // Vetëm zona reale të KËTIJ tenanti: një id i vjetruar/i sajuar përndryshe
        // përplaset me FK-në kompozite dhe rrëzon GJITHË ruajtjen me 500, në vend
        // që të kapërcehet siç premton endpoint-i.
        $knownZoneIds = BeachZone::query()->pluck('id')->flip();

        foreach ($prices as $zoneId => $price) {
            if (! isset($knownZoneIds[(int) $zoneId])) {
                continue;
            }

            if ($price === null || $price === '') {
                $season->prices()->where('beach_zone_id', (int) $zoneId)->delete();

                continue;
            }

            $season->prices()->updateOrCreate(
                ['beach_zone_id' => (int) $zoneId],
                ['price_per_day' => (float) $price],
            );
        }
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
