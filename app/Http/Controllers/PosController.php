<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FolioItem;
use App\Models\InventoryMovement;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosFiscalDocument;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosOrderPayment;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\BaseCurrency;
use App\Services\CurrencyRates;
use App\Services\FatureAlConfiguration;
use App\Services\FinanceLedger;
use App\Services\InventoryLedger;
use App\Services\PosFiscalizationService;
use App\Services\VatConfiguration;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PosController extends Controller
{
    public function __construct(
        private readonly InventoryLedger $inventoryLedger,
        private readonly FinanceLedger $financeLedger,
        private readonly PosFiscalizationService $fiscalization,
        private readonly FatureAlConfiguration $fatureAlConfiguration,
        private readonly TenantContext $tenantContext,
        private readonly VatConfiguration $vatConfiguration,
    ) {}

    public function index(Request $request): Response
    {
        $query = PosOrder::select(
            'id', 'reservation_id', 'table_number', 'status',
            'payment_method', 'subtotal_amount', 'discount_amount', 'discount_reason', 'is_complimentary',
            'total_amount', 'created_by', 'paid_at', 'business_date', 'cancelled_at', 'cancellation_reason',
            'refunded_at', 'refund_reason', 'created_at'
        )
            ->with(['createdBy:id,name', 'items.menuItem:id,name', 'payments', 'fiscalDocument'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            if ($request->status === 'refunded') {
                $query->whereNotNull('refunded_at');
            } else {
                $query->where('status', $request->status);
                if ($request->status === 'completed') {
                    $query->whereNull('refunded_at');
                }
            }
        }
        if ($request->integer('order_id')) {
            $query->whereKey($request->integer('order_id'));
        }

        // Active checked-in reservations for room charge
        $activeReservations = Reservation::where('status', 'checked_in')
            ->with(['room:id,room_number', 'guest:id,first_name,last_name'])
            ->select('id', 'room_id', 'guest_id')
            ->get();

        // Current user's open cash-drawer shift (per-user model), with live running totals.
        $shift = PosShift::currentFor(auth()->id());
        $currentShift = null;
        if ($shift) {
            $totals = $shift->liveTotals();
            $cash = $totals['cash'];
            $card = $totals['card'];
            $room = $totals['room_charge'];

            $currentShift = [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at?->format('H:i'),
                'opening_float' => (float) $shift->opening_float,
                'user_name' => auth()->user()->name,
                'open_orders' => (int) $shift->orders()->where('status', 'open')->count(),
                'completed_orders' => (int) $shift->orders()->where('status', 'completed')->count(),
                'cash_sales' => $cash,
                'card_sales' => $card,
                'room_charge_sales' => $room,
                // Only cash drives the drawer; card + room_charge are reported separately.
                'expected_cash' => round((float) $shift->opening_float + $cash, 2),
            ];
        }

        $salesCounts = PosOrderItem::query()
            ->whereHas('order', fn ($order) => $order
                ->where('status', 'completed')
                ->whereNull('refunded_at')
                ->where(function ($recent) {
                    $recent->where('paid_at', '>=', now()->subDays(30))
                        ->orWhere(function ($legacy) {
                            $legacy->whereNull('paid_at')->where('created_at', '>=', now()->subDays(30));
                        });
                }))
            ->selectRaw('menu_item_id, SUM(quantity) as quantity_sold')
            ->groupBy('menu_item_id')
            ->pluck('quantity_sold', 'menu_item_id');

        $warehouses = Warehouse::where('is_active', true)->get()->keyBy('id');
        $defaultWarehouse = $warehouses->firstWhere('is_default', true) ?? $warehouses->first();
        $warehouseStocks = InventoryMovement::query()
            ->selectRaw('warehouse_id, inventory_item_id, SUM(quantity) as quantity')
            ->groupBy('warehouse_id', 'inventory_item_id')->get()
            ->groupBy('warehouse_id')->map(fn ($rows) => $rows->pluck('quantity', 'inventory_item_id'));

        $menu = MenuCategory::with(['items' => fn ($query) => $query
            ->where('is_available', true)->with(['inventoryComponents', 'warehouse'])])
            ->orderBy('sort_order')
            ->get()
            ->each(function (MenuCategory $category) use ($salesCounts, $warehouses, $defaultWarehouse, $warehouseStocks) {
                $warehouse = $warehouses->get($category->warehouse_id)
                    ?? $warehouses->firstWhere('type', $category->outlet)
                    ?? $defaultWarehouse;
                $category->items->each(function (MenuItem $item) use ($salesCounts, $warehouse, $warehouseStocks) {
                    $itemWarehouse = $item->warehouse?->is_active ? $item->warehouse : $warehouse;
                    $components = $item->inventoryComponents;
                    $available = $components->isEmpty() || ! $itemWarehouse
                        ? null
                        : (int) floor($components->min(function ($component) use ($itemWarehouse, $warehouseStocks) {
                            $stock = (float) ($warehouseStocks->get($itemWarehouse->id)?->get($component->inventory_item_id) ?? 0);

                            return $stock / max(0.0001, (float) $component->quantity);
                        }));
                    $item->setAttribute('sales_count', (int) ($salesCounts[$item->id] ?? 0));
                    $item->setAttribute('inventory_tracked', $components->isNotEmpty());
                    $item->setAttribute('available_portions', $available);
                    $item->setAttribute('inventory_warehouse', $itemWarehouse?->name);
                    $item->unsetRelation('inventoryComponents');
                    $item->unsetRelation('warehouse');
                });
            });

        $orders = $query->paginate(15)->through(fn (PosOrder $order) => [
            'id' => $order->id,
            'reservation_id' => $order->reservation_id,
            'table_number' => $order->table_number,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'subtotal_amount' => (float) $order->subtotal_amount,
            'discount_amount' => (float) $order->discount_amount,
            'discount_reason' => $order->discount_reason,
            'is_complimentary' => (bool) $order->is_complimentary,
            'total_amount' => (float) $order->total_amount,
            'created_at' => $order->created_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'business_date' => $order->business_date?->toDateString(),
            'effective_status' => $order->refunded_at ? 'refunded' : $order->status,
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $order->cancellation_reason,
            'refunded_at' => $order->refunded_at?->toIso8601String(),
            'refund_reason' => $order->refund_reason,
            'payments' => $order->payments->map(fn (PosOrderPayment $payment) => [
                'method' => $payment->method,
                'direction' => $payment->direction,
                'amount' => (float) $payment->amount,
            ])->values(),
            'created_by' => $order->createdBy ? ['name' => $order->createdBy->name] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
                'menu_item' => ['name' => $item->menuItem?->name ?: 'Artikull POS'],
            ])->values(),
            'fiscal_document' => $this->fiscalDocumentPayload($order->fiscalDocument),
        ]);

        return Inertia::render('Pos/Index', [
            'orders' => $orders,
            'menu' => $menu,
            'activeReservations' => $activeReservations,
            'filters' => $request->only('status', 'order_id'),
            'currentShift' => $currentShift,
            'canOpenShift' => $request->user()->can('open_pos_shift'),
            'canCloseShift' => $request->user()->can('close_pos_shift'),
            'defaultOpeningFloat' => (float) Setting::get('pos.default_opening_float', 0),
            'receiptSettings' => $this->receiptSettings(),
            'stats' => [
                'open' => PosOrder::where('status', 'open')->count(),
                'today_completed' => PosOrder::where('status', 'completed')->whereNull('refunded_at')->where(function ($today) {
                    $today->whereDate('business_date', today())
                        ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereDate('paid_at', today()))
                        ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereNull('paid_at')->whereDate('created_at', today()));
                })->count(),
                'today_revenue' => PosOrder::where('status', 'completed')->whereNull('refunded_at')->where(function ($today) {
                    $today->whereDate('business_date', today())
                        ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereDate('paid_at', today()))
                        ->orWhere(fn ($legacy) => $legacy->whereNull('business_date')->whereNull('paid_at')->whereDate('created_at', today()));
                })->sum('total_amount'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'table_number' => ['nullable', 'string', 'max:10'],
            'reservation_id' => ['nullable', TenantRule::exists('reservations')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', TenantRule::exists('menu_items')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        // No order without an open cash-drawer shift for the acting user.
        $shift = PosShift::currentFor(auth()->id());
        if (! $shift) {
            AuditLog::record('pos.shift.blocked', null, ['attempted_action' => 'store']);

            return back()->with('error', 'Hap nje turn para se te krijosh porosi.');
        }

        $order = DB::transaction(function () use ($request, $shift) {
            $order = PosOrder::create([
                'table_number' => $request->table_number,
                'reservation_id' => $request->reservation_id,
                'pos_shift_id' => $shift->id,
                'status' => 'open',
                'created_by' => auth()->id(),
                'total_amount' => 0,
            ]);

            foreach ($request->items as $item) {
                $menuItem = MenuItem::findOrFail($item['menu_item_id']);
                $orderItem = PosOrderItem::create([
                    'pos_order_id' => $order->id,
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $menuItem->price,
                    'total_price' => $menuItem->price * $item['quantity'],
                ]);
                // Open orders reserve stock immediately so two terminals cannot oversell it.
                $this->inventoryLedger->consumePosOrderItem($orderItem, $request->user()->id);
            }

            $order->recalculateTotal();

            return $order;
        });

        return back()->with('success', "Porosia #{$order->id} u krijua — ".BaseCurrency::symbol().$order->total_amount);
    }

    public function update(Request $request, PosOrder $posOrder): RedirectResponse
    {
        $data = $request->validate([
            'table_number' => ['nullable', 'string', 'max:10'],
            'reservation_id' => ['nullable', TenantRule::exists('reservations')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', TenantRule::exists('menu_items')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        if ($posOrder->status !== 'open') {
            return back()->with('error', 'Vetëm porositë e hapura mund të ndryshohen.');
        }

        $shift = PosShift::currentFor($request->user()->id);
        if (! $shift) {
            return back()->with('error', 'Hap një turn para se të ndryshosh porosinë.');
        }

        DB::transaction(function () use ($posOrder, $data, $request, $shift) {
            $lockedOrder = PosOrder::query()->lockForUpdate()->findOrFail($posOrder->id);
            if ($lockedOrder->status !== 'open') {
                throw ValidationException::withMessages(['order' => 'Porosia nuk është më e hapur.']);
            }

            $lockedOrder->load('items');
            foreach ($lockedOrder->items as $oldItem) {
                $this->inventoryLedger->releasePosOrderItem($oldItem, 'sale_release', $request->user()->id);
                $oldItem->delete();
            }

            $lockedOrder->update([
                'table_number' => $data['table_number'] ?? null,
                'reservation_id' => $data['reservation_id'] ?? null,
                'pos_shift_id' => $shift->id,
            ]);

            foreach ($data['items'] as $item) {
                $menuItem = MenuItem::findOrFail($item['menu_item_id']);
                $orderItem = PosOrderItem::create([
                    'pos_order_id' => $lockedOrder->id,
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $menuItem->price,
                    'total_price' => $menuItem->price * $item['quantity'],
                ]);
                $this->inventoryLedger->consumePosOrderItem($orderItem, $request->user()->id);
            }

            $lockedOrder->recalculateTotal();
        });

        AuditLog::record('pos.update', $posOrder, ['amount' => $posOrder->fresh()->total_amount]);

        return back()->with('success', "Porosia #{$posOrder->id} u përditësua.");
    }

    public function complete(Request $request, PosOrder $posOrder): RedirectResponse
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'in:cash,card,room_charge'],
            'payments' => ['nullable', 'array', 'max:2'],
            'payments.*.method' => ['required_with:payments', 'in:cash,card,room_charge'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01', 'max:99999999.99'],
            'reservation_id' => ['nullable', TenantRule::exists('reservations')->where('status', 'checked_in')],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'complimentary' => ['nullable', 'boolean'],
        ]);

        if ($posOrder->status !== 'open') {
            return back()->with('error', 'Kjo porosi nuk eshte e hapur.');
        }

        // A sale only finalizes inside the acting user's open shift (cash hits a live drawer).
        $shift = PosShift::currentFor(auth()->id());
        if (! $shift) {
            AuditLog::record('pos.shift.blocked', $posOrder, ['attempted_action' => 'complete']);

            return back()->with('error', 'Hap nje turn para se te mbyllesh porosine.');
        }

        DB::transaction(function () use ($posOrder, $request, $shift, $data) {
            $order = PosOrder::query()->lockForUpdate()->findOrFail($posOrder->id);
            if ($order->status !== 'open') {
                throw ValidationException::withMessages(['order' => 'Porosia nuk është më e hapur.']);
            }

            $subtotal = round((float) $order->items()->sum('total_price'), 2);
            // Preserve the value of legacy/manual open tickets that predate item rows.
            if ($subtotal === 0.0 && (float) $order->total_amount > 0) {
                $subtotal = round((float) $order->total_amount, 2);
            }
            $complimentary = (bool) ($data['complimentary'] ?? false);
            $discount = $complimentary ? $subtotal : round((float) ($data['discount_amount'] ?? 0), 2);
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount_amount' => 'Ulja nuk mund të kalojë nëntotalin.']);
            }
            if ($discount > 0 && blank($data['discount_reason'] ?? null)) {
                throw ValidationException::withMessages(['discount_reason' => 'Shëno arsyen e uljes ose të komplimentares.']);
            }

            $total = round($subtotal - $discount, 2);
            $tenders = collect($data['payments'] ?? []);
            if ($tenders->isEmpty() && ! empty($data['payment_method']) && $total > 0) {
                $tenders = collect([['method' => $data['payment_method'], 'amount' => $total]]);
            }

            if ($total > 0 && abs((float) $tenders->sum('amount') - $total) > 0.009) {
                throw ValidationException::withMessages(['payments' => 'Shuma e pagesave duhet të jetë e barabartë me totalin.']);
            }
            if ($total === 0.0 && $tenders->isNotEmpty()) {
                throw ValidationException::withMessages(['payments' => 'Një porosi komplimentare nuk kërkon pagesë.']);
            }

            $methods = $tenders->pluck('method')->unique()->values();
            if ($methods->contains('room_charge') && ($methods->count() > 1 || $tenders->count() > 1)) {
                throw ValidationException::withMessages(['payments' => 'Pagesa në dhomë nuk mund të ndahet me cash ose kartë.']);
            }
            if ($methods->contains('room_charge') && empty($data['reservation_id'])) {
                throw ValidationException::withMessages(['reservation_id' => 'Zgjidh rezervimin aktiv për pagesën në dhomë.']);
            }

            $reservationId = $methods->contains('room_charge')
                ? $data['reservation_id']
                : $order->reservation_id;
            $legacyMethod = $methods->count() === 1 ? $methods->first() : null;

            $order->update([
                'status' => 'completed',
                'payment_method' => $legacyMethod,
                'reservation_id' => $reservationId,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'discount_reason' => $data['discount_reason'] ?? null,
                'is_complimentary' => $complimentary,
                'total_amount' => $total,
                'paid_at' => now(),
                'business_date' => today(),
                // Cash physically enters the drawer of whoever finalizes the sale, so attribute the
                // order to the completing user's shift — fixes cross-shift completion + legacy NULL orders.
                'pos_shift_id' => $shift->id,
            ]);

            foreach ($tenders as $tender) {
                $payment = PosOrderPayment::create([
                    'pos_order_id' => $order->id,
                    'pos_shift_id' => $shift->id,
                    'direction' => 'in',
                    'method' => $tender['method'],
                    'amount' => round((float) $tender['amount'], 2),
                    'paid_at' => now(),
                    'created_by' => $request->user()->id,
                ]);
                $this->financeLedger->recordPosOrderPayment($payment);
            }

            // Room charge → add a traceable line to the reservation folio
            if ($methods->contains('room_charge')) {
                $order->loadMissing('items.menuItem.category');
                FolioItem::create([
                    'reservation_id' => $reservationId,
                    'pos_order_id' => $order->id,
                    'description' => "POS Porosi #{$order->id}".($order->table_number ? " (Tavolina {$order->table_number})" : ''),
                    'amount' => $order->total_amount,
                    'type' => $order->items->first()?->menuItem?->category?->name === 'Pije' ? 'bar' : 'restaurant',
                    'charge_date' => today(),
                ]);
            }

            // Legacy open tickets may not have reserved stock yet; this remains idempotent.
            $order->loadMissing('items.menuItem.inventoryComponents');
            foreach ($order->items as $orderItem) {
                $this->inventoryLedger->consumePosOrderItem($orderItem, $request->user()->id);
            }
        });

        $posOrder->refresh()->load('payments');
        AuditLog::record('pos.complete', $posOrder, [
            'amount' => $posOrder->total_amount,
            'payment_methods' => $posOrder->payments->where('direction', 'in')->pluck('method')->values()->all(),
            'discount_amount' => $posOrder->discount_amount,
            'reservation_id' => $posOrder->reservation_id,
        ]);

        // Payment is intentionally independent from the external fiscal provider.
        // The operator can print a non-fiscal receipt immediately and fiscalize later.
        return back()->with('success', 'Pagesa u regjistrua. Fatura mund të printohet ose fiskalizohet veçmas.');
    }

    public function fiscalize(PosOrder $posOrder): RedirectResponse
    {
        try {
            $this->fiscalization->fiscalize($posOrder);

            return back()->with('success', 'Fatura POS u fiskalizua dhe është gati për printim.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'fiscalization' => $exception->getMessage(),
            ]);
        }
    }

    public function cancel(Request $request, PosOrder $posOrder): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        if ($posOrder->status !== 'open') {
            return back()->with('error', 'Vetem porosite e hapura mund te anulohen.');
        }

        // Voiding a ticket is a cash-control event — only inside the acting user's open shift.
        $shift = PosShift::currentFor(auth()->id());
        if (! $shift) {
            AuditLog::record('pos.shift.blocked', $posOrder, ['attempted_action' => 'cancel']);

            return back()->with('error', 'Hap nje turn para se te anulosh porosine.');
        }

        DB::transaction(function () use ($posOrder, $shift, $request, $data) {
            $order = PosOrder::query()->lockForUpdate()->findOrFail($posOrder->id);
            $order->load('items');
            foreach ($order->items as $orderItem) {
                $this->inventoryLedger->releasePosOrderItem($orderItem, 'sale_release', $request->user()->id);
            }

            $order->update([
                'status' => 'cancelled',
                'pos_shift_id' => $shift->id,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $data['reason'],
            ]);
        });

        AuditLog::record('pos.cancel', $posOrder, [
            'amount' => $posOrder->total_amount,
            'reason' => $data['reason'],
        ]);

        return back()->with('success', 'Porosia u anulua.');
    }

    public function refund(Request $request, PosOrder $posOrder): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        if ($posOrder->status !== 'completed' || $posOrder->refunded_at) {
            return back()->with('error', 'Vetëm një porosi e përfunduar dhe e parimbursuar mund të kthehet.');
        }

        $shift = PosShift::currentFor($request->user()->id);
        if (! $shift) {
            return back()->with('error', 'Hap një turn para se të regjistrosh rimbursimin.');
        }

        DB::transaction(function () use ($posOrder, $shift, $request, $data) {
            $order = PosOrder::query()->lockForUpdate()->with(['payments', 'items'])->findOrFail($posOrder->id);
            if ($order->refunded_at) {
                throw ValidationException::withMessages(['refund' => 'Porosia është rimbursuar tashmë.']);
            }

            $salePayments = $order->payments->where('direction', 'in');
            if ($salePayments->isEmpty() && (float) $order->total_amount > 0 && $order->payment_method) {
                // Backwards-compatible refund for orders completed before tender rows existed.
                $salePayments = collect([new PosOrderPayment([
                    'method' => $order->payment_method,
                    'amount' => $order->total_amount,
                ])]);
            }

            foreach ($salePayments as $salePayment) {
                $refund = PosOrderPayment::create([
                    'pos_order_id' => $order->id,
                    'pos_shift_id' => $shift->id,
                    'direction' => 'out',
                    'method' => $salePayment->method,
                    'amount' => $salePayment->amount,
                    'refunded_from_id' => $salePayment->exists ? $salePayment->id : null,
                    'paid_at' => now(),
                    'created_by' => $request->user()->id,
                ]);
                $this->financeLedger->recordPosOrderPayment($refund);
            }

            if ($salePayments->contains(fn ($payment) => $payment->method === 'room_charge')) {
                FolioItem::create([
                    'reservation_id' => $order->reservation_id,
                    'pos_order_id' => $order->id,
                    'description' => "Rimbursim POS Porosi #{$order->id}",
                    'amount' => -abs((float) $order->total_amount),
                    'type' => 'discount',
                    'charge_date' => today(),
                ]);
            }

            foreach ($order->items as $orderItem) {
                $this->inventoryLedger->releasePosOrderItem($orderItem, 'sale_return', $request->user()->id);
            }

            $order->update([
                'refunded_at' => now(),
                'refunded_by' => $request->user()->id,
                'refund_reason' => $data['reason'],
            ]);
        });

        AuditLog::record('pos.refund', $posOrder, [
            'amount' => $posOrder->total_amount,
            'reason' => $data['reason'],
        ]);

        return back()->with('success', "Porosia #{$posOrder->id} u rimbursua dhe stoku u kthye.");
    }

    private function receiptSettings(): array
    {
        $account = (array) $this->fatureAlConfiguration->get('account', []);
        $tenant = $this->tenantContext->tenant();

        return [
            'hotel_name' => Setting::get('hotel.name', $tenant?->name ?: 'Hotel'),
            'legal_name' => $account['company'] ?? null,
            'nipt' => $account['nipt'] ?? Setting::get('hotel.nipt'),
            'branch' => $account['branch'] ?? null,
            'address' => Setting::get('hotel.address'),
            'phone' => Setting::get('hotel.phone'),
            'currency' => strtoupper((string) ($tenant?->currency ?: 'EUR')),
            'exchange_rate' => CurrencyRates::rate('ALL'),
            'vat_status' => $this->vatConfiguration->status(),
            'tax_rate' => $this->vatConfiguration->productRate(),
        ];
    }

    private function fiscalDocumentPayload(?PosFiscalDocument $document): ?array
    {
        if (! $document) {
            return null;
        }

        return [
            'status' => $document->status,
            'environment' => $document->environment,
            'payment_method' => $document->payment_method,
            'currency' => $document->currency,
            'exchange_rate' => $document->exchange_rate !== null ? (float) $document->exchange_rate : null,
            'total' => (float) $document->total,
            'vat_rate' => (float) $document->vat_rate,
            'invoice_payload' => $document->invoice_payload,
            'fiscal_number' => $document->fiscal_number,
            'iic' => $document->iic,
            'fic' => $document->fic,
            'tcr_code' => $document->tcr_code,
            'business_code' => $document->business_code,
            'operator_code' => $document->operator_code,
            'fiscalized_at' => $document->fiscalized_at?->toIso8601String(),
            'verify_url' => $document->verify_url,
            'last_error' => $document->last_error,
        ];
    }
}
