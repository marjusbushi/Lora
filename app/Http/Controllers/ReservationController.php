<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationStoreRequest;
use App\Http\Requests\ReservationUpdateRequest;
use App\Models\AuditLog;
use App\Models\CleaningTask;
use App\Models\FiscalDocument;
use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\AuditTimeline;
use App\Services\BaseCurrency;
use App\Services\CurrencyRates;
use App\Services\FatureAlConfiguration;
use App\Services\InventoryLedger;
use App\Services\ReservationConflictService;
use App\Services\RoomPricing;
use App\Services\TenantBillingService;
use App\Services\VatConfiguration;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    public function index(Request $request): Response
    {
        $perPage = (int) $request->input('per_page', 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $sort = $request->input('sort', 'latest');
        if (! is_string($sort) || ! in_array($sort, ['latest', 'checkin', 'checkout'], true)) {
            $sort = 'latest';
        }

        $query = $this->reservationListQuery();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $searchTerms = collect(preg_split('/\s+/u', trim((string) $request->search)))
                ->filter()
                ->take(6);

            $query->where(function ($searchQuery) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $like = "%{$term}%";
                    $searchQuery->where(function ($termQuery) use ($term, $like) {
                        $termQuery->where('id', ctype_digit((string) $term) ? (int) $term : -1)
                            ->orWhere('channel_ref', 'like', $like)
                            ->orWhereHas('room', fn ($room) => $room->where('room_number', 'like', $like))
                            ->orWhereHas('guest', fn ($guest) => $guest
                                ->where('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('email', 'like', $like)
                                ->orWhere('phone', 'like', $like));
                    });
                }
            });
        }

        $today = today()->toDateString();

        if ($sort === 'checkin') {
            $query
                // Active arrivals come first: today/overdue, then the next arrivals.
                ->orderByRaw("CASE
                    WHEN status IN ('pending', 'confirmed') AND check_in_date <= ? THEN 0
                    WHEN status IN ('pending', 'confirmed') AND check_in_date > ? THEN 1
                    WHEN status = 'checked_in' THEN 2
                    WHEN status = 'checked_out' THEN 3
                    ELSE 4
                END", [$today, $today])
                ->orderByRaw("CASE
                    WHEN status IN ('pending', 'confirmed') AND check_in_date <= ?
                    THEN check_in_date
                END DESC", [$today])
                ->orderByRaw("CASE
                    WHEN status IN ('pending', 'confirmed') AND check_in_date > ?
                    THEN check_in_date
                END ASC", [$today])
                ->orderByDesc('check_in_date');
        } elseif ($sort === 'checkout') {
            $query
                // Guests already in-house are the operational checkout queue.
                ->orderByRaw("CASE
                    WHEN status = 'checked_in' AND check_out_date <= ? THEN 0
                    WHEN status = 'checked_in' AND check_out_date > ? THEN 1
                    WHEN status IN ('pending', 'confirmed') AND check_out_date >= ? THEN 2
                    WHEN status IN ('pending', 'confirmed') THEN 3
                    WHEN status = 'checked_out' THEN 4
                    ELSE 5
                END", [$today, $today, $today])
                ->orderByRaw("CASE
                    WHEN status = 'checked_in' AND check_out_date <= ?
                    THEN check_out_date
                END DESC", [$today])
                ->orderByRaw("CASE
                    WHEN status = 'checked_in' AND check_out_date > ?
                    THEN check_out_date
                END ASC", [$today])
                ->orderByRaw("CASE
                    WHEN status IN ('pending', 'confirmed') AND check_out_date >= ?
                    THEN check_out_date
                END ASC", [$today])
                ->orderByDesc('check_out_date');
        }

        // Stable tie-breakers also define the default "latest received" order.
        $query->orderByDesc('created_at')->orderByDesc('id');

        $filters = array_merge($request->only('status', 'search'), [
            'per_page' => $perPage,
            'sort' => $sort,
        ]);

        $reservations = $query->paginate($perPage)->appends($filters)
            ->through(fn (Reservation $reservation) => $this->reservationListRow($reservation, $request));

        $focusReservation = null;
        if ($focusId = $request->integer('reservation_id')) {
            $focus = $this->reservationListQuery()->find($focusId);
            $focusReservation = $focus ? $this->reservationListRow($focus, $request) : null;
        }

        return Inertia::render('Reservations/Index', [
            'reservations' => $reservations,
            'focusReservation' => $focusReservation,
            'latestReservationId' => Reservation::orderByDesc('created_at')->orderByDesc('id')->value('id'),
            'rooms' => Room::select('id', 'room_number', 'room_type_id')
                ->with('roomType:id,name,base_price,max_occupancy')
                ->orderBy('room_number')
                ->get(),
            'guests' => Guest::select('id', 'first_name', 'last_name', 'email', 'phone')
                ->orderBy('last_name')
                ->get(),
            'filters' => $filters,
            'channelFees' => Setting::get('financial.channel_fees', []),
            'stats' => [
                'total' => Reservation::count(),
                'pending' => Reservation::where('status', 'pending')->count(),
                'confirmed' => Reservation::where('status', 'confirmed')->count(),
                'checked_in' => Reservation::where('status', 'checked_in')->count(),
                'arrivals_today' => Reservation::whereDate('check_in_date', $today)
                    ->whereIn('status', ['pending', 'confirmed'])->count(),
            ],
        ]);
    }

    public function calendar(Request $request, ReservationConflictService $conflictService): Response
    {
        $visibleDays = (int) $request->input('days', 14);
        if (! in_array($visibleDays, [7, 14, 30], true)) {
            $visibleDays = 14;
        }

        $startDate = $request->input('start', now()->startOfWeek()->toDateString());
        $endDate = now()->parse($startDate)->addDays($visibleDays - 1)->toDateString();

        $rooms = Room::select('id', 'room_number', 'room_type_id', 'floor', 'status')
            ->with('roomType:id,name,base_price,max_occupancy')
            ->orderBy('floor')
            ->orderBy('room_number')
            ->get();

        $reservations = Reservation::select(
            'id', 'room_id', 'guest_id', 'check_in_date', 'check_out_date', 'status',
            'total_amount', 'adults', 'children', 'channel', 'channel_ref', 'created_via',
            'payment_collect', 'notes', 'eta', 'booking_group_id', 'created_at'
        )
            ->with('guest:id,first_name,last_name,phone,email,nationality')
            ->withSum(['payments as paid_amount' => fn ($query) => $query->notVoided()], 'amount')
            ->whereNotIn('status', ['cancelled'])
            ->where('check_in_date', '<=', $endDate)
            ->where('check_out_date', '>=', $startDate)
            ->get()
            // Send plain local Y-m-d (not ISO UTC datetimes) so the calendar's
            // string date comparisons line up — fixes the off-by-one / out-of-sync bars.
            ->map(fn ($r) => [
                'id' => $r->id,
                'room_id' => $r->room_id,
                'guest_id' => $r->guest_id,
                'check_in_date' => $r->check_in_date->toDateString(),
                'check_out_date' => $r->check_out_date->toDateString(),
                'status' => $r->status,
                'total_amount' => $r->total_amount,
                'adults' => $r->adults,
                'children' => $r->children,
                'channel' => $r->channel,
                'channel_ref' => $r->channel_ref,
                'created_via' => $r->created_via,
                'payment_collect' => $r->payment_collect,
                'notes' => $r->notes,
                'eta' => $r->eta,
                'paid_amount' => round((float) $r->paid_amount, 2),
                'booking_group_id' => $r->booking_group_id,
                'created_at' => $r->created_at?->toIso8601String(),
                'guest' => $r->guest ? [
                    'id' => $r->guest->id,
                    'first_name' => $r->guest->first_name,
                    'last_name' => $r->guest->last_name,
                    'phone' => $r->guest->phone,
                    'email' => $r->guest->email,
                    'nationality' => $r->guest->nationality,
                ] : null,
            ]);

        $guests = Guest::select('id', 'first_name', 'last_name', 'email', 'phone')
            ->orderBy('last_name')
            ->get();

        return Inertia::render('Reservations/CalendarLive', [
            'rooms' => $rooms,
            'reservations' => $reservations,
            'guests' => $guests,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'visibleDays' => $visibleDays,
            'channelFees' => Setting::get('financial.channel_fees', []),
            'conflicts' => $conflictService->detect($startDate, $endDate),
        ]);
    }

    public function show(
        Reservation $reservation,
        AuditTimeline $timeline,
        FatureAlConfiguration $fatureAlConfiguration,
        VatConfiguration $vatConfiguration,
    ): Response {
        $reservation->load([
            'room:id,room_number,room_type_id',
            'room.roomType:id,name,base_price',
            'guest:id,first_name,last_name,email,phone',
            'folioItems' => fn ($q) => $q->orderBy('charge_date')->orderBy('id'),
            'payments' => fn ($q) => $q->orderBy('created_at'),
        ]);

        // Balance = room charge + folio charges - discounts - payments.
        // total_amount stays the ROOM charge; the live balance is computed here.
        $roomCharge = (float) $reservation->total_amount;
        // total_amount already represents the room charge ("Qendrimi ne dhome"); any type='room'
        // folio lines (e.g. per-night room lines) would double-count it, so exclude them everywhere.
        $folioCharges = (float) $reservation->folioItems->whereNotIn('type', ['discount', 'room'])->sum('amount');
        $discounts = (float) $reservation->folioItems->where('type', 'discount')->sum('amount');
        $gross = round($roomCharge + $folioCharges - $discounts, 2);

        // Prices are VAT-inclusive. Discounts are distributed proportionally so
        // the 6% accommodation and 20% product bases stay mathematically correct.
        $grossBeforeDiscount = $roomCharge + $folioCharges;
        $discountFactor = $grossBeforeDiscount > 0
            ? max(0, min(1, $gross / $grossBeforeDiscount))
            : 1;
        $taxAmount = $vatConfiguration->taxPortion(
            $roomCharge * $discountFactor,
            $vatConfiguration->accommodationRate(),
        );
        foreach ($reservation->folioItems->whereNotIn('type', ['discount', 'room']) as $folioItem) {
            $taxAmount += $vatConfiguration->taxPortion(
                (float) $folioItem->amount * $discountFactor,
                $vatConfiguration->folioRate($folioItem->vat_rate),
            );
        }
        $taxAmount = round($taxAmount, 2);

        $paid = (float) $reservation->payments->reject(fn ($p) => $p->is_voided)->sum('amount');
        $outstanding = round($gross - $paid, 2);

        $openPosOrders = PosOrder::where('reservation_id', $reservation->id)
            ->where('status', 'open')
            ->select('id', 'table_number', 'total_amount', 'created_at')
            ->orderByDesc('created_at')
            ->get();

        $fiscalEnvironment = (string) $fatureAlConfiguration->get('environment', 'sandbox');
        $fiscalDocument = FiscalDocument::query()
            ->where('reservation_id', $reservation->id)
            ->where('provider', 'fature_al')
            ->where('environment', $fiscalEnvironment)
            ->first();
        $paymentMethods = $reservation->payments
            ->reject(fn ($payment) => $payment->is_voided)
            ->pluck('method')
            ->unique()
            ->values();
        $fiscalPaymentMethod = $paymentMethods->count() === 1
            && in_array($paymentMethods->first(), ['cash', 'card'], true)
                ? $paymentMethods->first()
                : ($paymentMethods->count() > 1 ? 'mixed' : null);

        $history = AuditLog::query()
            ->with('causer:id,name')
            ->where('subject_type', Reservation::class)
            ->where('subject_id', $reservation->id)
            ->where('action', '!=', 'fiscalization.retry_payload_updated')
            ->latest('id')
            ->limit(50)
            ->get();

        $tenant = app(TenantContext::class)->tenant();
        $fiscalAccount = (array) $fatureAlConfiguration->get('account', []);
        $providerVatStatus = data_get($fiscalAccount, 'issuer_in_vat');
        $providerVatMatches = ! is_bool($providerVatStatus)
            || $providerVatStatus === $vatConfiguration->registered();
        $inventoryEnabled = app(TenantBillingService::class)->enabled('finance', $tenant);
        $inventoryItems = collect();
        $inventoryWarehouses = collect();
        if ($inventoryEnabled) {
            $inventoryWarehouses = Warehouse::query()
                ->where('is_active', true)
                ->orderByRaw("CASE WHEN type = 'rooms' THEN 0 WHEN is_default = 1 THEN 1 ELSE 2 END")
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'is_default']);

            $items = InventoryItem::query()
                ->where('is_active', true)
                ->where('type', 'product')
                ->where('sell_in_rooms', true)
                ->whereNotNull('room_selling_price')
                ->where('room_selling_price', '>', 0)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit', 'image_path', 'room_selling_price', 'room_warehouse_id']);
            $stockMap = InventoryMovement::query()
                ->whereIn('inventory_item_id', $items->pluck('id'))
                ->whereIn('warehouse_id', $inventoryWarehouses->pluck('id'))
                ->selectRaw('inventory_item_id, warehouse_id, SUM(quantity) as quantity')
                ->groupBy('inventory_item_id', 'warehouse_id')
                ->get()
                ->groupBy('inventory_item_id');

            $inventoryItems = $items->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'unit' => $item->unit,
                'image_path' => $item->image_path,
                'selling_price' => (float) $item->room_selling_price,
                'room_warehouse_id' => $item->room_warehouse_id,
                'warehouse_stock' => collect($stockMap->get($item->id, []))
                    ->mapWithKeys(fn ($stock) => [(string) $stock->warehouse_id => round((float) $stock->quantity, 4)])
                    ->all(),
            ])->values();
        }

        return Inertia::render('Reservations/Show', [
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'check_in_date' => $reservation->check_in_date?->toDateString(),
                'check_out_date' => $reservation->check_out_date?->toDateString(),
                'nights' => $reservation->nights,
                'adults' => $reservation->adults,
                'children' => $reservation->children,
                'notes' => $reservation->notes,
                'channel' => $reservation->channel,
                'channel_ref' => $reservation->channel_ref,
                'payment_collect' => $reservation->payment_collect,
                'guest' => [
                    'id' => $reservation->guest?->id,
                    'name' => $reservation->guest?->full_name,
                    'email' => $reservation->guest?->email,
                    'phone' => $reservation->guest?->phone,
                ],
                'room' => [
                    'id' => $reservation->room?->id,
                    'room_number' => $reservation->room?->room_number,
                    'room_type' => $reservation->room?->roomType?->name,
                ],
                'links' => $this->reservationLinks($reservation, request()),
            ],
            'folio' => [
                'roomCharge' => $roomCharge,
                'items' => $reservation->folioItems
                    ->where('type', '!=', 'room')
                    ->values()
                    ->map(fn ($i) => [
                        'id' => $i->id,
                        'description' => $i->description,
                        'type' => $i->type,
                        'amount' => (float) $i->amount,
                        'vat_rate' => $i->vat_rate !== null ? (float) $i->vat_rate : null,
                        'charge_date' => $i->charge_date?->toDateString(),
                    ]),
                'discounts' => round($discounts, 2),
                'gross' => $gross,
                'vatStatus' => $vatConfiguration->status(),
                'accommodationVatRate' => $vatConfiguration->accommodationRate(),
                'productVatRate' => $vatConfiguration->productRate(),
                'taxAmount' => $taxAmount,
                'net' => round($gross - $taxAmount, 2),
                'paid' => round($paid, 2),
                'outstanding' => $outstanding,
            ],
            'payments' => $reservation->payments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'date' => $p->created_at?->toDateString(),
            ]),
            'openPosOrders' => $openPosOrders,
            'history' => $timeline->entries($history),
            'inventoryEnabled' => $inventoryEnabled,
            'inventoryItems' => $inventoryItems,
            'inventoryWarehouses' => $inventoryWarehouses,
            'currency' => BaseCurrency::symbol(),
            'invoicePrint' => [
                'hotel_name' => Setting::get('hotel.name', $tenant?->name ?: 'Hotel'),
                'legal_name' => $fiscalAccount['company'] ?? null,
                'nipt' => $fiscalAccount['nipt'] ?? Setting::get('hotel.nipt'),
                'branch' => $fiscalAccount['branch'] ?? null,
                'address' => Setting::get('hotel.address'),
                'phone' => Setting::get('hotel.phone'),
                'email' => Setting::get('hotel.email'),
                'currency' => strtoupper((string) ($tenant?->currency ?: 'EUR')),
                'exchange_rate' => $fiscalDocument?->exchange_rate !== null
                    ? (float) $fiscalDocument->exchange_rate
                    : CurrencyRates::rate('ALL'),
                'operator' => request()->user()?->name,
                'vat_status' => $vatConfiguration->status(),
                'accommodation_vat_rate' => $vatConfiguration->accommodationRate(),
                'product_vat_rate' => $vatConfiguration->productRate(),
            ],
            'fiscalization' => [
                'configured' => $fatureAlConfiguration->configured(),
                'verified' => $fatureAlConfiguration->verified(),
                'environment' => $fiscalEnvironment,
                'vat_configured' => $vatConfiguration->configured(),
                'vat_matches_provider' => $providerVatMatches,
                'payment_method' => $fiscalPaymentMethod,
                'can_issue' => $fatureAlConfiguration->configured()
                    && $fatureAlConfiguration->verified()
                    && $fiscalEnvironment === 'sandbox'
                    && $vatConfiguration->configured()
                    && $providerVatMatches
                    && $reservation->status === 'checked_out'
                    && in_array($fiscalPaymentMethod, ['cash', 'card'], true)
                    && $fiscalDocument?->status !== FiscalDocument::STATUS_FISCALIZED,
                'document' => $fiscalDocument ? [
                    'status' => $fiscalDocument->status,
                    'internal_id' => $fiscalDocument->internal_id,
                    'payment_method' => $fiscalDocument->payment_method,
                    'currency' => $fiscalDocument->currency,
                    'exchange_rate' => $fiscalDocument->exchange_rate !== null ? (float) $fiscalDocument->exchange_rate : null,
                    'total' => (float) $fiscalDocument->total,
                    'vat_rate' => (float) $fiscalDocument->vat_rate,
                    'invoice_payload' => $fiscalDocument->invoice_payload,
                    'fiscal_number' => $fiscalDocument->fiscal_number,
                    'iic' => $fiscalDocument->iic,
                    'fic' => $fiscalDocument->fic,
                    'tcr_code' => $fiscalDocument->tcr_code,
                    'business_code' => $fiscalDocument->business_code,
                    'operator_code' => $fiscalDocument->operator_code,
                    'fiscalized_at' => $fiscalDocument->fiscalized_at?->toIso8601String(),
                    'verify_url' => $fiscalDocument->verify_url,
                    'last_error' => $fiscalDocument->last_error,
                ] : null,
            ],
        ]);
    }

    public function addFolioLine(Request $request, Reservation $reservation): RedirectResponse
    {
        if (! in_array($reservation->status, ['pending', 'confirmed', 'checked_in'], true)) {
            throw ValidationException::withMessages([
                'type' => 'Nuk mund te shtosh tarife ne nje rezervim te anulluar ose te mbyllur.',
            ]);
        }

        $data = $request->validate([
            // Restaurant/bar charges must originate in POS so they remain linked
            // to the order and cannot be posted twice by hand.
            'type' => ['required', 'in:minibar,extra,discount'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'charge_date' => ['nullable', 'date'],
        ]);

        if ($data['type'] === 'minibar' && app(TenantBillingService::class)->enabled('finance', app(TenantContext::class)->tenant())) {
            throw ValidationException::withMessages([
                'type' => 'Shto produktin e minibar-it nga inventari që stoku dhe folio të përditësohen bashkë.',
            ]);
        }

        if ($data['type'] === 'discount') {
            $charges = (float) $reservation->total_amount
                + (float) $reservation->folioItems()->whereNotIn('type', ['discount', 'room'])->sum('amount');
            $discounts = (float) $reservation->folioItems()->where('type', 'discount')->sum('amount');
            $maximumDiscount = max(0, round($charges - $discounts, 2));

            if ((float) $data['amount'] > $maximumDiscount) {
                throw ValidationException::withMessages([
                    'amount' => 'Zbritja nuk mund te jete me e madhe se totali aktual.',
                ]);
            }
        }

        $reservation->folioItems()->create([
            'description' => $data['description'],
            'amount' => $data['amount'],
            'type' => $data['type'],
            'charge_date' => $data['charge_date'] ?? today(),
        ]);

        AuditLog::record('folio.add_line', $reservation, ['type' => $data['type'], 'amount' => $data['amount']]);

        return back()->with('success', 'Tarifa u shtua ne llogarine e mysafirit.');
    }

    public function addInventoryFolioLine(
        Request $request,
        Reservation $reservation,
        InventoryLedger $ledger,
    ): RedirectResponse {
        $data = $request->validate([
            'inventory_item_id' => [
                'required', 'integer',
                TenantRule::exists('inventory_items')->where('is_active', true)->where('type', 'product')->where('sell_in_rooms', true),
            ],
            'warehouse_id' => [
                'required', 'integer',
                TenantRule::exists('warehouses')->where('is_active', true),
            ],
            'quantity' => ['required', 'numeric', 'min:0.0001', 'max:10000'],
            'inventory_reference' => ['required', 'uuid'],
        ]);

        [$folioItem, $wasCreated] = DB::transaction(function () use ($reservation, $data, $request, $ledger) {
            $lockedReservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($lockedReservation->status !== 'checked_in') {
                throw ValidationException::withMessages([
                    'inventory_item_id' => 'Minibari mund të regjistrohet vetëm gjatë një qëndrimi aktiv.',
                ]);
            }

            $existingFolioItem = FolioItem::query()
                ->where('inventory_reference', $data['inventory_reference'])
                ->first();
            if ($existingFolioItem) {
                if ((int) $existingFolioItem->reservation_id !== (int) $lockedReservation->id) {
                    throw ValidationException::withMessages([
                        'inventory_reference' => 'Kjo kërkesë është përdorur më parë.',
                    ]);
                }

                $ledger->consumeFolioItem($existingFolioItem, $request->user()->id);

                return [$existingFolioItem, false];
            }

            $item = InventoryItem::query()->lockForUpdate()->findOrFail($data['inventory_item_id']);
            if ($item->room_warehouse_id && (int) $item->room_warehouse_id !== (int) $data['warehouse_id']) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'Ky produkt shitet nga magazina e konfiguruar për dhomat.',
                ]);
            }
            $price = (float) $item->room_selling_price;
            if ($price <= 0) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => 'Artikulli nuk ka çmim shitjeje të vlefshëm.',
                ]);
            }

            $quantity = round((float) $data['quantity'], 4);
            $folioItem = FolioItem::query()->create([
                'inventory_reference' => $data['inventory_reference'],
                'reservation_id' => $lockedReservation->id,
                'inventory_item_id' => $item->id,
                'warehouse_id' => $data['warehouse_id'],
                'inventory_quantity' => $quantity,
                'unit_price' => $price,
                'description' => 'Minibar · '.$item->name.' × '.rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.'),
                'amount' => round($quantity * $price, 2),
                'type' => 'minibar',
                'charge_date' => today(),
            ]);

            $ledger->consumeFolioItem($folioItem, $request->user()->id);

            return [$folioItem, true];
        });

        if ($wasCreated) {
            AuditLog::record('folio.add_inventory', $reservation, [
                'folio_item_id' => $folioItem->id,
                'inventory_item_id' => $folioItem->inventory_item_id,
                'warehouse_id' => $folioItem->warehouse_id,
                'quantity' => (float) $folioItem->inventory_quantity,
                'amount' => (float) $folioItem->amount,
            ]);
        }

        return back()->with('success', 'Minibari u shtua në folio dhe stoku u përditësua.');
    }

    public function recordPayment(Request $request, Reservation $reservation): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'method' => ['required', 'in:cash,card'],
        ]);

        $payment = DB::transaction(function () use ($reservation, $data) {
            $lockedReservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($lockedReservation->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'amount' => 'Nuk mund të regjistrohet pagesë për një rezervim të anulluar.',
                ]);
            }

            $charges = (float) $lockedReservation->total_amount
                + (float) $lockedReservation->folioItems()->whereNotIn('type', ['discount', 'room'])->sum('amount');
            $discounts = (float) $lockedReservation->folioItems()->where('type', 'discount')->sum('amount');
            $paid = (float) $lockedReservation->payments()->notVoided()->sum('amount');
            $outstanding = round($charges - $discounts - $paid, 2);

            if ($outstanding <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Ky rezervim është paguar plotësisht.',
                ]);
            }

            if ((float) $data['amount'] > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => 'Pagesa nuk mund të jetë më e madhe se shuma e mbetur prej '.number_format($outstanding, 2).' '.BaseCurrency::code().'.',
                ]);
            }

            return $lockedReservation->payments()->create([
                'amount' => $data['amount'],
                'method' => $data['method'],
                'currency' => BaseCurrency::code(),
                'created_by' => auth()->id(),
            ]);
        });

        AuditLog::record('payment.record', $reservation, ['amount' => $data['amount'], 'method' => $data['method']]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Pagesa u regjistrua.',
                'payment_id' => $payment->id,
            ], 201);
        }

        return back()->with('success', 'Pagesa u regjistrua.');
    }

    public function store(ReservationStoreRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $room = Room::with('roomType')->findOrFail($data['room_id']);

        $checkIn = now()->parse($data['check_in_date']);
        $checkOut = now()->parse($data['check_out_date']);
        $nights = $checkIn->diffInDays($checkOut);

        // Price: staff may enter the gross amount (OTA price, fee included); else default to room rate × nights.
        $entered = $data['total_amount'] ?? null;
        $data['total_amount'] = is_numeric($entered) && (float) $entered > 0
            ? round((float) $entered, 2)
            : RoomPricing::total($room->roomType, $checkIn, $checkOut);
        $data['created_by'] = auth()->id();
        $data['created_via'] = Reservation::CREATED_VIA_STAFF;
        $data['status'] = $data['status'] ?? 'pending';
        $data['channel'] = Reservation::normalizeChannel($data['channel'] ?? null);
        // Commission is ALWAYS derived server-side from the channel's configured % (locked).
        $data['commission_amount'] = $this->channelCommission($data['channel'], (float) $data['total_amount']);

        try {
            // The FormRequest already pre-checks availability; re-check under a row lock
            // inside the transaction to close the check-then-write race (no double-book).
            DB::transaction(function () use ($data, $room) {
                Room::where('id', $room->id)->lockForUpdate()->first();

                if (! Reservation::isRoomAvailable($room->id, $data['check_in_date'], $data['check_out_date'])) {
                    throw new \RuntimeException('room_unavailable');
                }

                Reservation::create($data);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Dhoma nuk eshte e disponueshme per keto data.');
        }

        return back()->with('success', 'Rezervimi u krijua me sukses.');
    }

    /**
     * Multi-room booking: one guest, N rooms, one reservation per room — all
     * sharing dates/channel and (when >1 room) a common booking_group_id so the
     * rooms can be managed together later. The guest is referenced once (not
     * duplicated). All-or-nothing: any unavailable/over-capacity room rolls back.
     */
    public function storeMulti(Request $request): RedirectResponse
    {
        $requestedChannel = $request->input('channel');
        if ($request->exists('channel') && (is_string($requestedChannel) || $requestedChannel === null)) {
            $request->merge(['channel' => Reservation::normalizeChannel($requestedChannel)]);
        }

        // Staff may back-date a booking (walk-in that already arrived, historical
        // entry) — no after_or_equal:today here. The PUBLIC website stays future-only.
        $data = $request->validate([
            'guest_id' => ['required', TenantRule::exists('guests')],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'status' => ['sometimes', 'in:pending,confirmed'],
            'channel' => ['sometimes', 'nullable', Rule::in(Reservation::CHANNELS)],
            'channel_ref' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.room_id' => ['required', TenantRule::exists('rooms')],
            'rooms.*.adults' => ['required', 'integer', 'min:1', 'max:20'],
            'rooms.*.children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'rooms.*.total_amount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'guest_id.required' => 'Zgjidh nje mysafir.',
            'check_in_date.required' => 'Vendos daten e hyrjes (check-in).',
            'check_out_date.required' => 'Vendos daten e daljes (check-out).',
            'check_out_date.after' => 'Data e daljes duhet te jete pas dates se hyrjes.',
            'rooms.required' => 'Shto te pakten nje dhome.',
            'rooms.min' => 'Shto te pakten nje dhome.',
            'rooms.*.room_id.required' => 'Zgjidh dhomen per cdo rresht.',
        ]);

        // No duplicate room within the same booking.
        $roomIds = array_column($data['rooms'], 'room_id');
        if (count($roomIds) !== count(array_unique($roomIds))) {
            throw ValidationException::withMessages([
                'rooms' => 'Nuk mund te zgjedhesh te njejten dhome dy here ne te njejtin rezervim.',
            ]);
        }

        $nights = now()->parse($data['check_in_date'])->diffInDays(now()->parse($data['check_out_date']));
        $channel = Reservation::normalizeChannel($data['channel'] ?? null);
        $status = $data['status'] ?? 'pending';
        $multi = count($data['rooms']) > 1;

        try {
            DB::transaction(function () use ($data, $channel, $status, $multi) {
                $groupId = $multi ? (string) Str::uuid() : null;

                foreach ($data['rooms'] as $row) {
                    $room = Room::with('roomType')->findOrFail($row['room_id']);

                    // Capacity safety net (the UI also caps this).
                    $maxOcc = $room->roomType?->max_occupancy;
                    $persons = (int) $row['adults'] + (int) ($row['children'] ?? 0);
                    if ($maxOcc && $persons > $maxOcc) {
                        throw new \RuntimeException("over_capacity:{$room->room_number}:{$maxOcc}");
                    }

                    // A room under maintenance is out of service regardless of dates —
                    // give a clear reason (not the misleading "booked for these dates").
                    if ($room->status === 'maintenance') {
                        throw new \RuntimeException("maintenance:{$room->room_number}");
                    }

                    // Row lock + re-check availability inside the transaction (no double-book).
                    Room::where('id', $room->id)->lockForUpdate()->first();
                    if (! Reservation::isRoomAvailable($room->id, $data['check_in_date'], $data['check_out_date'])) {
                        throw new \RuntimeException("room_unavailable:{$room->room_number}");
                    }

                    $entered = $row['total_amount'] ?? null;
                    $total = is_numeric($entered) && (float) $entered > 0
                        ? round((float) $entered, 2)
                        : RoomPricing::total($room->roomType, $data['check_in_date'], $data['check_out_date']);

                    Reservation::create([
                        'room_id' => $room->id,
                        'guest_id' => $data['guest_id'],
                        'created_by' => auth()->id(),
                        'created_via' => Reservation::CREATED_VIA_STAFF,
                        'check_in_date' => $data['check_in_date'],
                        'check_out_date' => $data['check_out_date'],
                        'status' => $status,
                        'total_amount' => $total,
                        'adults' => $row['adults'],
                        'children' => $row['children'] ?? 0,
                        'notes' => $data['notes'] ?? null,
                        'channel' => $channel,
                        'channel_ref' => $data['channel_ref'] ?? null,
                        'commission_amount' => $this->channelCommission($channel, (float) $total),
                        'booking_group_id' => $groupId,
                    ]);
                }
            });
        } catch (\RuntimeException $e) {
            // Business failures come back as a 422 validation error (keyed 'rooms')
            // so Inertia fires onError — NOT onSuccess. Returning a redirect here
            // would look like success to the client and falsely close the modal.
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'over_capacity:')) {
                [, $rn, $cap] = explode(':', $msg);
                $err = "Dhoma {$rn} lejon maksimumi {$cap} persona.";
            } elseif (str_starts_with($msg, 'maintenance:')) {
                [, $rn] = explode(':', $msg);
                $err = "Dhoma {$rn} eshte ne mirembajtje. Ndrysho statusin e dhomes te 'Dhomat' per ta rezervuar.";
            } elseif (str_starts_with($msg, 'room_unavailable:')) {
                [, $rn] = explode(':', $msg);
                $err = "Dhoma {$rn} eshte e zene per keto data (ka nje rezervim tjeter).";
            } else {
                $err = 'Rezervimi nuk u krijua.';
            }
            throw ValidationException::withMessages(['rooms' => $err]);
        }

        $count = count($data['rooms']);

        return back()->with('success', $count > 1
            ? "U krijuan {$count} rezervime per kete mysafir."
            : 'Rezervimi u krijua me sukses.');
    }

    public function update(ReservationUpdateRequest $request, Reservation $reservation): RedirectResponse
    {
        $data = $request->validated();

        $requestedChannel = Reservation::normalizeChannel($data['channel'] ?? $reservation->channel);
        if ($reservation->created_via !== Reservation::CREATED_VIA_STAFF
            && $requestedChannel !== Reservation::normalizeChannel($reservation->channel)) {
            throw ValidationException::withMessages([
                'channel' => 'Burimi i nje rezervimi te sinkronizuar nuk mund te ndryshohet.',
            ]);
        }

        $room = Room::with('roomType')->findOrFail($data['room_id']);

        $checkIn = now()->parse($data['check_in_date']);
        $checkOut = now()->parse($data['check_out_date']);
        $nights = $checkIn->diffInDays($checkOut);
        $entered = $data['total_amount'] ?? null;
        $data['total_amount'] = is_numeric($entered) && (float) $entered > 0
            ? round((float) $entered, 2)
            : RoomPricing::total($room->roomType, $checkIn, $checkOut);
        $data['channel'] = $requestedChannel;
        $data['commission_amount'] = $this->channelCommission($data['channel'], (float) $data['total_amount']);

        $reservation->update($data);

        return back()->with('success', 'Rezervimi u perditesua.');
    }

    /**
     * Seasonal price quote for the create/edit form. The client sends only the
     * room + dates; the amount is computed SERVER-SIDE from RoomPricing (seasons +
     * rate overrides). It never accepts a price from the client — this only feeds
     * the form's suggested value, and the store/update paths recompute it anyway.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', TenantRule::exists('rooms')],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
        ]);

        $room = Room::with('roomType')->findOrFail($data['room_id']);
        $quote = RoomPricing::quote($room->roomType, $data['check_in'], $data['check_out']);

        return response()->json([
            'nights' => $quote['nights'],
            'total' => $quote['total'],
        ]);
    }

    /**
     * Commission a channel keeps on a booking, from the configured % (settings
     * financial.channel_fees). Direct or any unconfigured channel = 0.
     */
    private function channelCommission(?string $channel, float $total): float
    {
        $channel = Reservation::normalizeChannel($channel);
        if ($channel === 'direct') {
            return 0.0;
        }

        $fees = (array) Setting::get('financial.channel_fees', []);
        $pct = isset($fees[$channel]) && is_numeric($fees[$channel]) ? (float) $fees[$channel] : 0.0;

        return round($total * $pct / 100, 2);
    }

    public function checkIn(Reservation $reservation): RedirectResponse
    {
        DB::transaction(function () use ($reservation) {
            $lockedReservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($lockedReservation->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'check_in' => 'Vetem rezervimet e konfirmuara mund te bejne check-in.',
                ]);
            }

            $room = Room::query()->lockForUpdate()->find($lockedReservation->room_id);
            $roomNotReady = ! $room
                || $room->status !== 'available'
                || CleaningTask::query()
                    ->where('room_id', $lockedReservation->room_id)
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->exists();
            if ($roomNotReady) {
                throw ValidationException::withMessages([
                    'check_in' => $room
                        ? "Dhoma {$room->room_number} eshte ende ne pastrim ose nuk eshte e lire. Perfundo pastrimin te Housekeeping para check-in."
                        : 'Dhoma e lidhur me rezervimin nuk ekziston me. Cakto nje dhome tjeter para check-in.',
                ]);
            }

            $lockedReservation->update(['status' => 'checked_in']);
            $room->update(['status' => 'occupied']);
        });

        $reservation->refresh()->loadMissing('room');

        return back()->with('success', "Check-in per dhomen {$reservation->room->room_number} u krye.");
    }

    /**
     * Move a CHECKED-IN guest to a different room (upgrade / room problem). Only the
     * room changes — dates, guest, folio and total stay the same. The new room must be
     * free for the stay dates; the old room goes to cleaning + a housekeeping task.
     */
    public function moveRoom(Request $request, Reservation $reservation): RedirectResponse
    {
        if (! auth()->user()->can('update_reservations')) {
            abort(403);
        }

        if ($reservation->status !== 'checked_in') {
            throw ValidationException::withMessages([
                'room_id' => 'Zhvendosja e dhomes lejohet vetem per mysafiret brenda (checked-in). Perndryshe perdor Edito.',
            ]);
        }

        $data = $request->validate([
            'room_id' => ['required', TenantRule::exists('rooms')],
        ]);

        if ((int) $data['room_id'] === (int) $reservation->room_id) {
            throw ValidationException::withMessages([
                'room_id' => 'Zgjidh nje dhome tjeter nga ajo aktuale.',
            ]);
        }

        $newRoom = Room::with('roomType')->findOrFail($data['room_id']);
        $oldRoom = $reservation->room;

        try {
            DB::transaction(function () use ($reservation, $newRoom, $oldRoom) {
                Room::where('id', $newRoom->id)->lockForUpdate()->first();

                if (! Reservation::isRoomAvailable(
                    $newRoom->id,
                    $reservation->check_in_date->toDateString(),
                    $reservation->check_out_date->toDateString(),
                    $reservation->id
                )) {
                    throw new \RuntimeException('unavailable');
                }

                $reservation->update(['room_id' => $newRoom->id]);
                $newRoom->update(['status' => 'occupied']);

                // The room the guest left needs cleaning — mirror check-out's housekeeping.
                if ($oldRoom) {
                    $oldRoom->update(['status' => 'cleaning']);

                    if (Setting::get('housekeeping.auto_create_on_checkout', true)) {
                        $alreadyOpen = CleaningTask::where('room_id', $oldRoom->id)
                            ->where('type', 'checkout_clean')
                            ->whereIn('status', ['pending', 'in_progress'])
                            ->exists();

                        if (! $alreadyOpen) {
                            CleaningTask::create([
                                'room_id' => $oldRoom->id,
                                'type' => 'checkout_clean',
                                'status' => 'pending',
                                'priority' => Setting::get('housekeeping.default_priority', 'normal'),
                            ]);
                        }
                    }
                }
            });
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'room_id' => "Dhoma {$newRoom->room_number} nuk eshte e lire per keto data.",
            ]);
        }

        return back()->with('success', "Mysafiri u zhvendos te dhoma {$newRoom->room_number}.");
    }

    public function resolveConflict(Request $request, Reservation $reservation, ReservationConflictService $conflictService): RedirectResponse
    {
        $data = $request->validate([
            'room_id' => ['required', TenantRule::exists('rooms')],
        ]);

        DB::transaction(function () use ($reservation, $data, $conflictService) {
            $lockedReservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $newRoom = Room::query()->with('roomType')->lockForUpdate()->findOrFail($data['room_id']);

            if (! in_array($lockedReservation->status, ['pending', 'confirmed'], true)) {
                throw ValidationException::withMessages([
                    'room_id' => 'Vetem rezervimet ne pritje ose te konfirmuara mund te zgjidhen nga qendra e konflikteve.',
                ]);
            }

            if ((int) $newRoom->id === (int) $lockedReservation->room_id) {
                throw ValidationException::withMessages(['room_id' => 'Zgjidh nje dhome tjeter.']);
            }

            if (! $conflictService->hasConflict($lockedReservation)) {
                throw ValidationException::withMessages([
                    'room_id' => 'Konflikti nuk ekziston me. Rifresko kalendarin.',
                ]);
            }

            $guestCount = (int) $lockedReservation->adults + (int) $lockedReservation->children;

            if (($newRoom->roomType?->max_occupancy ?? 0) < $guestCount
                || ! Reservation::isRoomAvailable(
                    $newRoom->id,
                    $lockedReservation->check_in_date->toDateString(),
                    $lockedReservation->check_out_date->toDateString(),
                    $lockedReservation->id
                )) {
                throw ValidationException::withMessages([
                    'room_id' => "Dhoma {$newRoom->room_number} nuk eshte me e lire ose nuk ka kapacitetin e nevojshem.",
                ]);
            }

            $lockedReservation->update(['room_id' => $newRoom->id]);
        });

        return back()->with('success', 'Konflikti u zgjidh dhe rezervimi u zhvendos.');
    }

    public function checkOut(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'checked_in') {
            return back()->with('error', 'Vetem mysafiret brenda mund te bejne check-out.');
        }

        // Don't let a guest leave with an unsettled bar/restaurant tab still open.
        $openOrders = PosOrder::where('reservation_id', $reservation->id)
            ->where('status', 'open')
            ->count();
        if ($openOrders > 0) {
            return back()->with('error', "Ka {$openOrders} porosi POS te hapura per kete rezervim — mbyllini perpara check-out.");
        }

        // Checkout settles the bill: the invoice is marked paid (cash/card) and only THEN does the guest leave.
        $data = $request->validate([
            'settle_method' => ['nullable', 'in:cash,card'],
        ]);

        // Live outstanding balance — same formula as the folio view: room charge + extra folio
        // charges − discounts − payments already taken (voided ones excluded, per Payment::notVoided).
        $reservation->loadMissing('folioItems', 'payments');
        $roomCharge = (float) $reservation->total_amount;
        $folioCharges = (float) $reservation->folioItems->whereNotIn('type', ['discount', 'room'])->sum('amount');
        $discounts = (float) $reservation->folioItems->where('type', 'discount')->sum('amount');
        $paid = (float) $reservation->payments->reject(fn ($p) => $p->is_voided)->sum('amount');
        $outstanding = round($roomCharge + $folioCharges - $discounts - $paid, 2);

        // MANDATORY payment before check-out: a guest who still owes cannot leave. Enforced HERE at
        // the backend so NO path can bypass it — the reservation list and calendar both POST an empty
        // body, and would otherwise check a guest out unpaid. To clear the bill the caller passes
        // settle_method (cash/card) and the payment is recorded below; a balance already at 0 (e.g. an
        // OTA prepaid stay) checks out straight through.
        if ($outstanding > 0.005 && empty($data['settle_method'])) {
            throw ValidationException::withMessages([
                'settle_method' => 'Klienti ka '.number_format($outstanding, 2).' '.BaseCurrency::code().' pa paguar — regjistro pagesën para check-out.',
            ]);
        }

        $roomNumber = null;

        DB::transaction(function () use ($reservation, $data, $outstanding, &$roomNumber) {
            // The linked room may have been soft-deleted after an old reservation was created.
            // Checkout must still close the guest safely instead of crashing with a 500.
            $room = Room::query()->lockForUpdate()->find($reservation->room_id);
            $roomNumber = $room?->room_number;

            // Record a payment for whatever is still owed, with the chosen method, before flipping status.
            if (! empty($data['settle_method']) && $outstanding > 0) {
                $reservation->payments()->create([
                    'amount' => $outstanding,
                    'method' => $data['settle_method'],
                    'currency' => BaseCurrency::code(),
                    'created_by' => auth()->id(),
                ]);
                AuditLog::record('payment.record', $reservation, [
                    'amount' => $outstanding,
                    'method' => $data['settle_method'],
                    'context' => 'checkout_settle',
                ]);
            }

            $reservation->update(['status' => 'checked_out']);
            $room?->update(['status' => 'cleaning']);

            // Auto-create a housekeeping task so the cleaning board reflects the checkout
            // (activates the housekeeping.auto_create_on_checkout setting, previously dead).
            if ($room && Setting::get('housekeeping.auto_create_on_checkout', true)) {
                $alreadyOpen = CleaningTask::where('room_id', $reservation->room_id)
                    ->where('type', 'checkout_clean')
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->exists();

                if (! $alreadyOpen) {
                    CleaningTask::create([
                        'room_id' => $reservation->room_id,
                        'type' => 'checkout_clean',
                        'status' => 'pending',
                        'priority' => Setting::get('housekeeping.default_priority', 'normal'),
                    ]);
                }
            }
        });

        $message = $roomNumber
            ? "Check-out per dhomen {$roomNumber} u krye."
            : 'Check-out u krye. Dhoma e vjeter e lidhur me rezervimin nuk ekziston me.';

        return back()->with('success', $message);
    }

    /**
     * Front desk requests a stayover (daily) cleaning while the guest is still in-house.
     * Creates a stayover_clean task WITHOUT ending the stay or touching room status —
     * the guest stays put, the room stays occupied. Can be requested again next day.
     */
    public function requestCleaning(Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'checked_in') {
            return back()->with('error', 'Pastrimi ditor mund te kerkohet vetem kur klienti eshte brenda (check-in).');
        }

        // One open stayover task per room is enough — don't stack duplicates.
        $alreadyOpen = CleaningTask::where('room_id', $reservation->room_id)
            ->where('type', 'stayover_clean')
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();
        if ($alreadyOpen) {
            return back()->with('error', 'Ka tashme nje pastrim ditor ne pritje per kete dhome.');
        }

        CleaningTask::create([
            'room_id' => $reservation->room_id,
            'type' => 'stayover_clean',
            'status' => 'pending',
            'priority' => Setting::get('housekeeping.default_priority', 'normal'),
            'notes' => 'Kerkuar nga recepsioni per klientin ne dhome.',
        ]);

        AuditLog::record('housekeeping.stayover_requested', $reservation, ['room' => $reservation->room->room_number]);

        return back()->with('success', 'Pastrimi ditor u kerkua — housekeeping do ta shohe ne board.');
    }

    private function reservationListQuery(): Builder
    {
        return Reservation::query()
            ->select(
                'id', 'room_id', 'guest_id', 'check_in_date', 'check_out_date',
                'status', 'total_amount', 'adults', 'children', 'channel', 'channel_ref',
                'payment_collect', 'notes', 'created_via', 'created_at'
            )
            ->with([
                'room:id,room_number,room_type_id',
                'room.roomType:id,name',
                'guest:id,first_name,last_name,email,phone',
            ])
            ->withSum(['payments as paid_amount' => fn ($query) => $query->notVoided()], 'amount')
            ->withSum(['folioItems as extra_charges' => fn ($query) => $query->whereNotIn('type', ['discount', 'room'])], 'amount')
            ->withSum(['folioItems as discount_amount' => fn ($query) => $query->where('type', 'discount')], 'amount');
    }

    private function reservationListRow(Reservation $reservation, Request $request): array
    {
        $gross = round(
            (float) $reservation->total_amount
            + (float) $reservation->extra_charges
            - (float) $reservation->discount_amount,
            2
        );
        $paid = round((float) $reservation->paid_amount, 2);

        return [
            'id' => $reservation->id,
            'room_id' => $reservation->room_id,
            'guest_id' => $reservation->guest_id,
            'check_in_date' => $reservation->check_in_date?->toDateString(),
            'check_out_date' => $reservation->check_out_date?->toDateString(),
            'nights' => $reservation->nights,
            'status' => $reservation->status,
            'total_amount' => (float) $reservation->total_amount,
            'gross_amount' => $gross,
            'paid_amount' => $paid,
            'outstanding_amount' => round($gross - $paid, 2),
            'adults' => $reservation->adults,
            'children' => $reservation->children,
            'channel' => $reservation->channel,
            'channel_ref' => $reservation->channel_ref,
            'payment_collect' => $reservation->payment_collect,
            'notes' => $reservation->notes,
            'created_via' => $reservation->created_via,
            'created_at' => $reservation->created_at?->toIso8601String(),
            'guest' => $reservation->guest ? [
                'id' => $reservation->guest->id,
                'first_name' => $reservation->guest->first_name,
                'last_name' => $reservation->guest->last_name,
                'name' => $reservation->guest->full_name,
                'email' => $reservation->guest->email,
                'phone' => $reservation->guest->phone,
            ] : null,
            'room' => $reservation->room ? [
                'id' => $reservation->room->id,
                'room_number' => $reservation->room->room_number,
                'room_type' => $reservation->room->roomType ? [
                    'name' => $reservation->room->roomType->name,
                ] : null,
            ] : null,
            'links' => $this->reservationLinks($reservation, $request),
        ];
    }

    private function reservationLinks(Reservation $reservation, Request $request): array
    {
        return [
            'show' => route('reservations.show', $reservation),
            'guest' => $request->user()?->can('view_guests') && $reservation->guest_id
                ? route('guests.show', $reservation->guest_id)
                : null,
            'room' => $request->user()?->can('view_rooms') && $reservation->room?->room_number
                ? route('rooms.index', ['search' => $reservation->room->room_number])
                : null,
            'finance' => $request->user()?->can('view_finance')
                ? route('finance.payments', ['reservation_id' => $reservation->id, 'all_dates' => 1])
                : null,
        ];
    }

    public function cancel(Reservation $reservation): RedirectResponse
    {
        if (in_array($reservation->status, ['checked_in', 'checked_out'])) {
            return back()->with('error', 'Nuk mund te anulohet nje rezervim aktiv ose i perfunduar.');
        }

        $reservation->update(['status' => 'cancelled']);

        return back()->with('success', 'Rezervimi u anulua.');
    }

    public function destroy(Reservation $reservation): RedirectResponse
    {
        if (! auth()->user()->can('delete_reservations')) {
            abort(403);
        }

        $reservation->delete();

        return back()->with('success', 'Rezervimi u fshi.');
    }
}
