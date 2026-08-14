<?php

namespace App\Http\Controllers;

use App\Http\Requests\Beach\StoreBeachReservationRequest;
use App\Http\Requests\Beach\UpdateBeachReservationRequest;
use App\Models\BeachReservation;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BeachReservationController extends Controller
{
    public function calendar(Request $request): Response
    {
        $visibleDays = (int) $request->input('days', 14);
        if (! in_array($visibleDays, [7, 14, 30], true)) {
            $visibleDays = 14;
        }

        $startDate = $request->input('start', now()->toDateString());
        $endDate = now()->parse($startDate)->addDays($visibleDays - 1)->toDateString();

        $reservations = BeachReservation::query()
            ->where('status', '!=', BeachReservation::STATUS_CANCELLED)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->get()
            // Data të thjeshta lokale Y-m-d (jo ISO UTC) — njësoj si kalendari i
            // dhomave, që krahasimet e datave në grid të mos dalin me një ditë gabim.
            ->map(fn (BeachReservation $reservation) => [
                'id' => $reservation->id,
                'beach_unit_id' => $reservation->beach_unit_id,
                'reservation_id' => $reservation->reservation_id,
                'guest_name' => $reservation->guest_name,
                'guest_phone' => $reservation->guest_phone,
                'guest_email' => $reservation->guest_email,
                'start_date' => $reservation->start_date->toDateString(),
                'end_date' => $reservation->end_date->toDateString(),
                'status' => $reservation->status,
                'source' => $reservation->source,
                'total_amount' => $reservation->total_amount,
                'paid_at' => $reservation->paid_at?->toDateTimeString(),
                'payment_method' => $reservation->payment_method,
            ]);

        return Inertia::render('Beach/Calendar', [
            'zones' => BeachZone::query()
                ->with(['units' => fn ($query) => $query->orderBy('sort_order')->orderBy('number')])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'reservations' => $reservations,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'visibleDays' => $visibleDays,
            'hotelToday' => now()->toDateString(),
            'season' => [
                'start' => (string) Setting::get('beach.season_start', ''),
                'end' => (string) Setting::get('beach.season_end', ''),
            ],
            // Turni i hapur i userit — UI e përdor për gating-un e pagesave + banner.
            'currentShift' => tap(\App\Models\BeachShift::currentFor((int) auth()->id()), fn ($shift) => $shift?->setAttribute('live_expected_cash', $shift->liveExpectedCash())),
            'currency' => \App\Services\PricingCurrency::symbol(),
        ]);
    }

    public function store(StoreBeachReservationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $reservation = DB::transaction(function () use ($request, $data) {
            $unit = $this->lockUnit((int) $data['beach_unit_id']);

            $this->assertAvailable($unit->id, $data['start_date'], $data['end_date']);

            return BeachReservation::create([
                ...$data,
                'status' => $data['status'] ?? BeachReservation::STATUS_CONFIRMED,
                'source' => BeachReservation::SOURCE_RECEPTION,
                // Çmimi VETËM server-side — klienti s'dërgon dot shumën.
                'total_amount' => $this->totalFor($unit, $data['start_date'], $data['end_date']),
                'created_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', "Rezervimi i çadrës {$reservation->unit->number} u ruajt.");
    }

    public function update(UpdateBeachReservationRequest $request, BeachReservation $beachReservation): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $beachReservation) {
            $unit = $this->lockUnit((int) ($data['beach_unit_id'] ?? $beachReservation->beach_unit_id));
            $start = $data['start_date'] ?? $beachReservation->start_date->toDateString();
            $end = $data['end_date'] ?? $beachReservation->end_date->toDateString();

            $this->assertAvailable($unit->id, $start, $end, $beachReservation->id);

            $newTotal = $this->totalFor($unit, $start, $end);

            // Shuma e një rezervimi të PAGUAR është e ngrirë — ajo përputhet me çfarë
            // ka kapur POK-u ose me Z-raportin e turnit. Lëvizje me total të njëjtë
            // (p.sh. çadër tjetër me të njëjtin çmim) lejohet; ndryshim çmimi jo.
            if ($beachReservation->paid_at && abs($newTotal - (float) $beachReservation->total_amount) > 0.005) {
                throw ValidationException::withMessages([
                    'beach_unit_id' => 'Rezervimi është i paguar — çmimi s\'ndryshohet. Hiq shënimin e pagesës më parë.',
                ]);
            }

            $beachReservation->update([
                ...$data,
                'beach_unit_id' => $unit->id,
                'total_amount' => $newTotal,
            ]);
        });

        return back()->with('success', 'Rezervimi u përditësua.');
    }

    public function cancel(BeachReservation $beachReservation): RedirectResponse
    {
        // Rezervimi i PAGUAR s'anullohet drejtpërdrejt — përndryshe e ardhura fshihet
        // nga Financa pa asnjë rimbursim real dhe turni mban një shitje fantazmë.
        if ($beachReservation->paid_at) {
            throw ValidationException::withMessages([
                'status' => $beachReservation->payment_method === 'online'
                    ? "Rezervim i paguar online — anullimi kërkon rimbursim në POK dhe s'bëhet nga kalendari."
                    : 'Hiq shënimin e pagesës para se ta anullosh rezervimin.',
            ]);
        }

        $beachReservation->update(['status' => BeachReservation::STATUS_CANCELLED]);

        return back()->with('success', 'Rezervimi u anullua — çadra u lirua.');
    }

    /** Shënon pagesën e marrë NË PLAZH (cash/kartë atje) — vetëm një herë, me turn të hapur. */
    public function markPaid(Request $request, BeachReservation $beachReservation): RedirectResponse
    {
        $data = $request->validate(['method' => ['required', 'in:cash,card']]);

        // Si POS-i: paraja e plazhit hyn gjithmonë në një turn të hapur të userit,
        // që sirtari të mbyllet me numërim dhe diferenca të shkojë në Financë.
        // Kyçja mbi rreshtin e turnit e serializon shënimin me MBYLLJEN e turnit —
        // pagesa ose hyn para snapshot-it të Z-raportit, ose refuzohet (turn i mbyllur).
        DB::transaction(function () use ($beachReservation, $data) {
            $shift = \App\Models\BeachShift::where('user_id', (int) auth()->id())
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                throw ValidationException::withMessages([
                    'method' => 'Hap turnin e plazhit para se të shënosh pagesa.',
                ]);
            }

            // Flip atomik: vetëm një rezervim ende i papaguar dhe jo i anulluar shënohet —
            // dy klikime të njëkohshme s'e shënojnë dot dy herë.
            $flipped = BeachReservation::whereKey($beachReservation->id)
                ->whereNull('paid_at')
                ->where('status', '!=', BeachReservation::STATUS_CANCELLED)
                ->update(['paid_at' => now(), 'payment_method' => $data['method'], 'beach_shift_id' => $shift->id]);

            if ($flipped !== 1) {
                throw ValidationException::withMessages([
                    'method' => 'Ky rezervim është shënuar tashmë i paguar ose është i anulluar.',
                ]);
            }
        });

        // Flip-i bulk nuk ndez observer-at — sinkronizo ledger-in shprehimisht
        // (best-effort: Financa s'guxon të bllokojë pagesën në plazh).
        try {
            app(\App\Services\FinanceLedger::class)->recordBeachPayment($beachReservation->fresh());
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('success', 'Pagesa u shënua.');
    }

    /** Heq shënimin e gabuar të pagesës në plazh — kurrë pagesat online (POK). */
    public function unmarkPaid(BeachReservation $beachReservation): RedirectResponse
    {
        if ($beachReservation->payment_method === 'online') {
            throw ValidationException::withMessages([
                'method' => "Pagesa online me kartë nuk hiqet nga këtu.",
            ]);
        }

        // Turni i mbyllur ka Z-raport të ngrirë — historia s'ndryshohet më.
        if ($beachReservation->beach_shift_id
            && \App\Models\BeachShift::withoutGlobalScopes()->whereKey($beachReservation->beach_shift_id)->value('status') === 'closed') {
            throw ValidationException::withMessages([
                'method' => 'Turni i kësaj pagese është mbyllur — shënimi s\'hiqet më.',
            ]);
        }

        $beachReservation->update(['paid_at' => null, 'payment_method' => null, 'beach_shift_id' => null]);

        return back()->with('success', 'Shënimi i pagesës u hoq.');
    }

    private function lockUnit(int $unitId): BeachUnit
    {
        /** @var BeachUnit $unit */
        $unit = BeachUnit::query()->whereKey($unitId)->lockForUpdate()->firstOrFail();

        return $unit->loadMissing('zone');
    }

    private function assertAvailable(int $unitId, string $start, string $end, ?int $excludeId = null): void
    {
        if (! BeachReservation::isUnitAvailable($unitId, $start, $end, $excludeId)) {
            throw ValidationException::withMessages([
                'beach_unit_id' => 'Çadra është e zënë në këto data — zgjidh çadër ose data të tjera.',
            ]);
        }
    }

    private function totalFor(BeachUnit $unit, string $start, string $end): float
    {
        // Ditë INKLUZIVE: 15–17 = 3 ditë plazh.
        $days = Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;

        return round($days * (float) $unit->zone->price_per_day, 2);
    }
}
