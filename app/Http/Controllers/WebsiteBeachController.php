<?php

namespace App\Http\Controllers;

use App\Models\BeachReservation;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\Setting;
use App\Models\User;
use App\Services\BeachPokPayments;
use App\Services\PokClient;
use App\Services\PokConfiguration;
use App\Services\PricingCurrency;
use App\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteBeachController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Website/BookSunbeds', [
            'zones' => $this->activeZones(),
            'bookingWindowDays' => $this->windowDays(),
            'season' => $this->season(),
            'today' => now()->toDateString(),
        ]);
    }

    public function availability(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $this->assertWithinPublicWindow($request->start_date, $request->end_date);

        $busyUnitIds = BeachReservation::query()
            ->where('status', '!=', BeachReservation::STATUS_CANCELLED)
            ->where('start_date', '<=', $request->end_date)
            ->where('end_date', '>=', $request->start_date)
            ->pluck('beach_unit_id')
            ->unique()
            ->values();

        return response()->json(['busy_unit_ids' => $busyUnitIds]);
    }

    public function submit(Request $request): RedirectResponse
    {
        // Honeypot — bots e mbushin këtë fushë të fshehur; vizitorët realë jo.
        if ($request->filled('website')) {
            return redirect()->route('website.home');
        }

        $data = $request->validate([
            'beach_unit_id' => ['required', 'integer', TenantRule::exists('beach_units')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'guest_name' => ['required', 'string', 'max:150', new \App\Rules\ContainsLetters(2)],
            'guest_phone' => ['required', 'string', 'max:50'],
            'guest_email' => ['nullable', 'email', 'max:255'],
        ]);

        $this->assertWithinPublicWindow($data['start_date'], $data['end_date']);

        $creator = User::systemForCurrentTenant();

        $reservation = DB::transaction(function () use ($data, $creator) {
            /** @var BeachUnit $unit */
            $unit = BeachUnit::query()
                ->whereKey($data['beach_unit_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $unit->loadMissing('zone');

            if (! $unit->is_active || ! $unit->zone->is_active) {
                throw ValidationException::withMessages([
                    'beach_unit_id' => 'Kjo çadër nuk është në shërbim — zgjidh një tjetër.',
                ]);
            }

            if (! BeachReservation::isUnitAvailable($unit->id, $data['start_date'], $data['end_date'])) {
                throw ValidationException::withMessages([
                    'beach_unit_id' => 'Çadra u zu ndërkohë — zgjidh një çadër tjetër ose data të tjera.',
                ]);
            }

            $days = Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1;

            return BeachReservation::create([
                ...$data,
                'status' => BeachReservation::STATUS_PENDING,
                'source' => BeachReservation::SOURCE_WEBSITE,
                'total_amount' => round($days * (float) $unit->zone->price_per_day, 2),
                'confirmation_token' => Str::random(40),
                'created_by' => $creator->id,
            ]);
        });

        return redirect()->route('website.beach.confirmation', $reservation->confirmation_token);
    }

    /**
     * QR-ja e printuar në çadër është E PËRJETSHME (/s/{token}). Në V1 çon te
     * rezervimi; në V2 do të çojë te menuja e barit me çadrën e para-plotësuar.
     */
    public function qr(string $qrToken): RedirectResponse
    {
        BeachUnit::query()->where('qr_token', $qrToken)->firstOrFail();

        return redirect()->route('website.beach');
    }

    public function confirmation(string $token): Response
    {
        $reservation = BeachReservation::query()
            ->where('confirmation_token', $token)
            ->with('unit.zone')
            ->firstOrFail();

        return Inertia::render('Website/BookSunbedsConfirmation', [
            'reservation' => [
                'unit_number' => $reservation->unit->number,
                'zone_name' => $reservation->unit->zone->name,
                'start_date' => $reservation->start_date->toDateString(),
                'end_date' => $reservation->end_date->toDateString(),
                'days' => $reservation->totalDays(),
                'total_amount' => $reservation->total_amount,
                'guest_name' => $reservation->guest_name,
                'status' => $reservation->status,
                'paid_at' => $reservation->paid_at?->toDateTimeString(),
                'confirmation_url' => route('website.beach.confirmation', $token),
                'pay_url' => route('website.beach.pay', $token),
                // Pagesa online ofrohet vetëm kur POK është i konfiguruar dhe s'është paguar ende.
                'pok_enabled' => app(PokClient::class)->configured()
                    && $reservation->paid_at === null
                    && $reservation->status !== BeachReservation::STATUS_CANCELLED
                    && (float) $reservation->total_amount > 0,
            ],
        ]);
    }

    /**
     * Faqja e pagesës POK (sandbox-ready). Ndryshe nga dhomat, pagesa e çadrës
     * është OPSIONALE — rezervimi mbetet i vlefshëm "paguaj në plazh" edhe pa të,
     * ndaj order-i POK krijohet vetëm kur klienti zgjedh "Paguaj online".
     */
    public function payment(string $token): Response|RedirectResponse
    {
        $reservation = BeachReservation::query()
            ->where('confirmation_token', $token)
            ->with('unit.zone')
            ->firstOrFail();

        $pok = app(PokClient::class);

        if ($reservation->paid_at
            || $reservation->status === BeachReservation::STATUS_CANCELLED
            || ! $pok->configured()
            || (float) $reservation->total_amount <= 0) {
            return redirect()->route('website.beach.confirmation', $token);
        }

        if (! $reservation->pok_order_id) {
            try {
                // Shuma VETËM nga DB — klienti s'e dërgon kurrë.
                $order = $pok->createOrder((float) $reservation->total_amount, PricingCurrency::code(), [
                    'webhook' => route('website.pay.webhook'),
                    'redirect' => route('website.beach.pay', $token),
                    'fail' => route('website.beach.pay', $token),
                    'expires' => 30,
                ]);
                $reservation->update(['pok_order_id' => $order['id']]);
            } catch (\Throwable $e) {
                report($e);

                return redirect()->route('website.beach.confirmation', $token)
                    ->with('error', "Nuk u lidh dot pagesa me kartë. Provo sërish pas pak — rezervimi yt mbetet i vlefshëm.");
            }
        }

        // Klienti mund të ketë paguar tashmë (confirm i humbur / kthim mbrapa) —
        // ri-verifiko PARA se t'i tregosh një formë karte live.
        try {
            if (app(BeachPokPayments::class)->settle($reservation)) {
                return redirect()->route('website.beach.confirmation', $token);
            }
        } catch (\Throwable $e) {
            report($e);

            return Inertia::render('Website/BookSunbedsPayment', array_merge(
                $this->beachPaymentProps($reservation, $token),
                ['openForPayment' => false],
            ));
        }

        return Inertia::render('Website/BookSunbedsPayment', array_merge(
            $this->beachPaymentProps($reservation, $token),
            ['openForPayment' => true],
        ));
    }

    /** Browser-i e thërret pas onSuccess të formës — verifikim server-side, kurrë besim te klienti. */
    public function paymentConfirm(string $token): RedirectResponse
    {
        $reservation = BeachReservation::query()
            ->where('confirmation_token', $token)
            ->firstOrFail();

        try {
            app(BeachPokPayments::class)->settle($reservation);
        } catch (\Throwable $e) {
            report($e); // webhook-u është rrjeta e sigurisë — mos i jep 500 klientit
        }

        if ($reservation->fresh()->paid_at) {
            return redirect()->route('website.beach.confirmation', $token);
        }

        return redirect()->route('website.beach.pay', $token)
            ->with('error', "Pagesa s'u konfirmua ende. Nëse e paguat, prit pak sekonda dhe rifresko.");
    }

    /** @return array<string, mixed> */
    private function beachPaymentProps(BeachReservation $reservation, string $token): array
    {
        return [
            'orderId' => $reservation->pok_order_id,
            'env' => app(PokConfiguration::class)->get('production', false) ? 'production' : 'staging',
            'amount' => (float) $reservation->total_amount,
            'currency' => PricingCurrency::symbol(),
            'confirmUrl' => route('website.beach.pay.confirm', $token),
            'confirmationUrl' => route('website.beach.confirmation', $token),
            'payUrl' => rtrim(app(PokConfiguration::class)->payUrl(), '/').'/sdk-orders/'.$reservation->pok_order_id,
            'initialState' => array_filter([
                'holdersName' => $reservation->guest_name ?: null,
                'email' => $reservation->guest_email ?: null,
                'phoneNumber' => $reservation->guest_phone ?: null,
            ], fn ($v) => $v !== null && $v !== ''),
            'unitNumber' => $reservation->unit->number,
            'zoneName' => $reservation->unit->zone->name,
            'days' => $reservation->totalDays(),
            'startDate' => $reservation->start_date->toDateString(),
            'endDate' => $reservation->end_date->toDateString(),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, BeachZone> */
    private function activeZones()
    {
        return BeachZone::query()
            ->where('is_active', true)
            ->with(['units' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('number')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'price_per_day', 'sort_order']);
    }

    private function windowDays(): int
    {
        return max(1, (int) Setting::get('beach.booking_window_days', 10));
    }

    /** @return array{start: string, end: string} */
    private function season(): array
    {
        return [
            'start' => (string) Setting::get('beach.season_start', ''),
            'end' => (string) Setting::get('beach.season_end', ''),
        ];
    }

    /**
     * Publiku rezervon vetëm brenda dritares [sot, sot + window ditë] (kufij
     * INKLUZIVË — me window=10, dita e 10-të lejohet, e 11-ta jo) dhe brenda
     * sezonit kur sezoni është i caktuar. Recepsioni s'ka këtë kufi.
     */
    private function assertWithinPublicWindow(string $startDate, string $endDate): void
    {
        $today = now()->startOfDay();
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        $lastAllowed = $today->copy()->addDays($this->windowDays());

        if ($start->lt($today)) {
            throw ValidationException::withMessages([
                'start_date' => 'Data e fillimit nuk mund të jetë në të shkuarën.',
            ]);
        }

        if ($end->gt($lastAllowed)) {
            throw ValidationException::withMessages([
                'end_date' => "Rezervimi online lejohet deri {$this->windowDays()} ditë përpara (deri më {$lastAllowed->toDateString()}).",
            ]);
        }

        $season = $this->season();
        if ($season['start'] !== '' && $season['end'] !== '') {
            $seasonStart = Carbon::parse($season['start'])->startOfDay();
            $seasonEnd = Carbon::parse($season['end'])->startOfDay();

            if ($start->lt($seasonStart) || $end->gt($seasonEnd)) {
                throw ValidationException::withMessages([
                    'start_date' => "Plazhi është i hapur nga {$seasonStart->toDateString()} deri {$seasonEnd->toDateString()}.",
                ]);
            }
        }
    }
}
