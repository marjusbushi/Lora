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

            $beachReservation->update([
                ...$data,
                'beach_unit_id' => $unit->id,
                'total_amount' => $this->totalFor($unit, $start, $end),
            ]);
        });

        return back()->with('success', 'Rezervimi u përditësua.');
    }

    public function cancel(BeachReservation $beachReservation): RedirectResponse
    {
        $beachReservation->update(['status' => BeachReservation::STATUS_CANCELLED]);

        return back()->with('success', 'Rezervimi u anullua — çadra u lirua.');
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
