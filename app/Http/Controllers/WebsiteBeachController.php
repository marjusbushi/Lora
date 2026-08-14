<?php

namespace App\Http\Controllers;

use App\Models\BeachReservation;
use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosOutlet;
use App\Models\Setting;
use App\Models\User;
use App\Services\InventoryLedger;
use App\Services\BeachPokPayments;
use App\Services\BeachPricing;
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
            'currency' => PricingCurrency::symbol(),
            'paymentMode' => $this->paymentMode(),
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

        return response()->json([
            'busy_unit_ids' => $busyUnitIds,
            // Per zonë: totali i intervalit + min/max ditor — UI-ja publike
            // shfaq çmimin real të datave pa e llogaritur vetë.
            'zone_pricing' => app(BeachPricing::class)->breakdown(
                $this->activeZones(),
                $request->start_date,
                $request->end_date,
            ),
        ]);
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

            return BeachReservation::create([
                ...$data,
                'status' => BeachReservation::STATUS_PENDING,
                'source' => BeachReservation::SOURCE_WEBSITE,
                // Çmim sezonal ditë-për-ditë, VETËM server-side (BeachPricing).
                'total_amount' => app(BeachPricing::class)->totalFor($unit, $data['start_date'], $data['end_date']),
                'confirmation_token' => Str::random(40),
                'created_by' => $creator->id,
            ]);
        });

        // Mënyra 'online': klienti çohet direkt te pagesa (rezervimi ruhet gjithsesi;
        // nëse POK s'është i konfiguruar, biem natyrshëm te konfirmimi).
        if ($this->paymentMode() === 'online' && app(PokClient::class)->configured()) {
            return redirect()->route('website.beach.pay', $reservation->confirmation_token);
        }

        return redirect()->route('website.beach.confirmation', $reservation->confirmation_token);
    }

    /**
     * QR-ja e printuar në çadër është E PËRJETSHME (/s/{token}). Kur admini ka
     * caktuar pikën POS të plazhit (beach.pos_outlet_id), QR-ja hap POROSINË
     * nga çadra; pa të, ruhet sjellja V1 — redirect te rezervimi.
     */
    public function qr(string $qrToken): Response|RedirectResponse
    {
        $unit = BeachUnit::query()->where('qr_token', $qrToken)->with('zone')->firstOrFail();
        $outlet = $this->orderingOutlet();

        if (! $outlet) {
            return redirect()->route('website.beach');
        }

        return Inertia::render('Website/BeachOrder', [
            'unit' => ['number' => $unit->number, 'zone_name' => $unit->zone->name],
            'qrToken' => $qrToken,
            'outletName' => $outlet->name,
            'menu' => $this->publicMenu($outlet),
            'currency' => PricingCurrency::symbol(),
            'reserveUrl' => route('website.beach'),
        ]);
    }

    /** Porosia nga çadra: PosOrder normale, e vulosur me pikën + çadrën, pa login. */
    public function order(Request $request, string $qrToken): RedirectResponse
    {
        $unit = BeachUnit::query()->where('qr_token', $qrToken)->firstOrFail();
        $outlet = $this->orderingOutlet();

        if (! $outlet) {
            throw ValidationException::withMessages([
                'order' => 'Porositë nga plazhi nuk janë aktive për momentin — drejtohu te bari.',
            ]);
        }

        $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.menu_item_id' => ['required', 'distinct', TenantRule::exists('menu_items')->where('is_available', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        // The menu HIDES other outlets' items — the order must REFUSE them too
        // (board finding: a crafted id would otherwise bypass visibility and
        // draw the beach outlet's stock for a restaurant-only item).
        $requestedIds = collect($request->items)->pluck('menu_item_id')->map(fn ($id) => (int) $id);
        $visibleIds = MenuItem::query()
            ->whereIn('id', $requestedIds)
            ->whereHas('category', fn ($query) => $query->visibleForOutlet($outlet->id))
            ->pluck('id');
        if ($requestedIds->diff($visibleIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Disa artikuj nuk ofrohen nga kjo pikë — rifresko menunë.',
            ]);
        }

        // A photographed QR is a forever-valid credential — cap the damage a
        // bot can do: at most 3 OPEN orders per sunbed, whatever the IP count.
        if (PosOrder::query()->where('beach_unit_id', $unit->id)->where('status', 'open')->count() >= 3) {
            throw ValidationException::withMessages([
                'order' => 'Keni tashmë porosi në pritje për këtë çadër — prit dorëzimin para se të porositësh sërish.',
            ]);
        }

        // Attribution: the same self-healing system user as public room bookings
        // (soft-delete guard included — the #30 outage lesson).
        $creator = User::systemForCurrentTenant();

        $order = DB::transaction(function () use ($request, $unit, $outlet, $creator) {
            $order = PosOrder::create([
                'outlet_id' => $outlet->id,
                'beach_unit_id' => $unit->id,
                // Staff-facing location; the column is 10 chars (typical unit
                // numbers are 1-3 chars) — beach_unit_id carries full identity.
                'table_number' => mb_substr('Çadra '.$unit->number, 0, 10),
                'guest_token' => Str::random(40),
                'status' => 'open',
                'created_by' => $creator->id,
                'total_amount' => 0,
                'business_date' => today(),
            ]);

            foreach ($request->items as $line) {
                $menuItem = MenuItem::findOrFail($line['menu_item_id']);
                $orderItem = PosOrderItem::create([
                    'pos_order_id' => $order->id,
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $menuItem->price,
                    'total_price' => $menuItem->price * $line['quantity'],
                ]);
                // Stock reserves immediately, exactly like the staff POS — an
                // out-of-stock item aborts the WHOLE order (transaction).
                app(InventoryLedger::class)->consumePosOrderItem($orderItem, $creator->id);
            }

            $order->recalculateTotal();

            return $order;
        });

        return redirect()->route('website.beach.order.status', $order->guest_token);
    }

    /** Statusi publik i porosisë me guest_token — "paguaj në dorëzim". */
    public function orderStatus(string $guestToken): Response
    {
        $order = PosOrder::query()
            ->where('guest_token', $guestToken)
            ->with(['items.menuItem:id,name', 'beachUnit.zone'])
            ->firstOrFail();

        return Inertia::render('Website/BeachOrderStatus', [
            'order' => [
                'unit_number' => $order->beachUnit?->number,
                'zone_name' => $order->beachUnit?->zone?->name,
                'status' => $order->refunded_at ? 'refunded' : ($order->cancelled_at ? 'cancelled' : $order->status),
                'total_amount' => (float) $order->total_amount,
                'currency' => PricingCurrency::symbol(),
                'created_at' => $order->created_at->format('H:i'),
                'items' => $order->items->map(fn (PosOrderItem $item) => [
                    'name' => $item->menuItem?->name ?? 'Artikull',
                    'quantity' => (int) $item->quantity,
                    'total_price' => (float) $item->total_price,
                ])->values(),
                'status_url' => route('website.beach.order.status', $guestToken),
            ],
        ]);
    }

    /** Pika POS ku rrugëtohen porositë e plazhit — e caktuar te Settings → Plazhi. */
    private function orderingOutlet(): ?PosOutlet
    {
        $outletId = (int) Setting::get('beach.pos_outlet_id', 0);

        return $outletId ? PosOutlet::active()->find($outletId) : null;
    }

    /** Menuja publike e pikës së plazhit — respekton dukshmërinë per pikë (#408). */
    private function publicMenu(PosOutlet $outlet): array
    {
        return MenuCategory::query()
            ->visibleForOutlet($outlet->id)
            ->with(['items' => fn ($query) => $query->where('is_available', true)->orderBy('name')])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (MenuCategory $category) => $category->items->isNotEmpty())
            ->map(fn (MenuCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'items' => $category->items->map(fn (MenuItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'price' => (float) $item->price,
                    'image_path' => $item->image_path,
                ])->values(),
            ])->values()->all();
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
                'currency' => PricingCurrency::symbol(),
                'guest_name' => $reservation->guest_name,
                'status' => $reservation->status,
                'paid_at' => $reservation->paid_at?->toDateTimeString(),
                'confirmation_url' => route('website.beach.confirmation', $token),
                'pay_url' => route('website.beach.pay', $token),
                'payment_mode' => $this->paymentMode(),
                // Pagesa online ofrohet sipas cilësimit të hotelit (kurrë në mënyrën 'cash')
                // dhe vetëm kur POK është i konfiguruar e s'është paguar ende.
                'pok_enabled' => $this->paymentMode() !== 'cash'
                    && app(PokClient::class)->configured()
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
            || $this->paymentMode() === 'cash'
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

    /** cash = vetëm në plazh · online = vetëm me kartë · both = klienti zgjedh (default). */
    private function paymentMode(): string
    {
        $mode = (string) Setting::get('beach.payment_mode', 'both');

        return in_array($mode, ['cash', 'online', 'both'], true) ? $mode : 'both';
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
