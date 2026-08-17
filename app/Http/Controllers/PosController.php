<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FolioItem;
use App\Models\InventoryCategory;
use App\Models\InventoryMovement;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosFiscalDocument;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosOrderPayment;
use App\Models\PosOutlet;
use App\Models\PosShift;
use App\Models\PosTable;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\BaseCurrency;
use App\Services\CurrencyRates;
use App\Services\FatureAlConfiguration;
use App\Services\FinanceLedger;
use App\Services\InventoryLedger;
use App\Services\PosFiscalizationService;
use App\Services\PosSalespersonService;
use App\Services\VatConfiguration;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        private readonly PosSalespersonService $posSalespeople,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $view = match ($request->route()?->getName()) {
            'pos.orders' => 'orders',
            'pos.receipts' => 'receipts',
            'pos.shifts' => 'shifts',
            default => 'sale',
        };
        $posSettings = $this->posSalespeople->settings();
        $outlets = PosOutlet::active()->ordered()->get(['id', 'name', 'warehouse_id']);
        $currentOutlet = $this->resolveOutlet($request, $outlets);
        if ($view === 'sale' && ! $request->integer('table') && ! $request->boolean('direct')) {
            if ($posSettings['service_mode'] === 'tables'
                || ($posSettings['service_mode'] === 'hybrid' && $posSettings['opening_view'] === 'tables')) {
                return redirect()->route('pos.tables');
            }
        }
        $tableContext = null;
        if ($view === 'sale' && $request->integer('table')) {
            $table = PosTable::query()
                ->where('is_active', true)
                ->findOrFail($request->integer('table'));
            $tableContext = [
                'id' => $table->id,
                'number' => $table->number,
                'name' => $table->name,
                'area' => $table->area,
                'seats' => $table->seats,
            ];
            // Serving a table means serving ITS outlet: the menu must match
            // what storeRound will stamp, even if the device last remembered
            // another outlet (back-navigation, shared handhelds).
            if ($table->outlet_id && ($tableOutlet = $outlets->firstWhere('id', $table->outlet_id))) {
                $currentOutlet = $tableOutlet;
            }
        }
        $request->validate([
            'outlet' => ['nullable', TenantRule::exists('pos_outlets')],
        ]);

        $query = PosOrder::select(
            'id', 'reservation_id', 'table_number', 'pos_table_id', 'outlet_id', 'beach_unit_id', 'status',
            'payment_method', 'subtotal_amount', 'discount_amount', 'discount_reason', 'is_complimentary',
            'total_amount', 'created_by', 'salesperson_id', 'cashier_id', 'paid_at', 'business_date', 'cancelled_at', 'cancellation_reason',
            'refunded_at', 'refund_reason', 'created_at'
        )
            ->with(['createdBy:id,name', 'salesperson:id,name', 'cashier:id,name', 'items.menuItem:id,name', 'payments', 'fiscalDocument', 'beachUnit:id,beach_zone_id,number', 'beachUnit.zone:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('outlet')) {
            $query->where('outlet_id', $request->integer('outlet'));
        }

        if (! $request->filled('status') && ! $request->integer('order_id')) {
            if ($view === 'orders') {
                $query->where('status', 'open');
            } elseif ($view === 'receipts') {
                $query->where('status', '!=', 'open');
            }
        }

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

        $shiftHistory = $view === 'shifts'
            ? PosShift::with(['user:id,name', 'closedBy:id,name', 'currencies'])
                ->orderByDesc('opened_at')
                ->limit(30)
                ->get()
                ->map(function (PosShift $item) {
                    // Live totals for EVERY open shift (not just the viewer's own):
                    // the force-close modal must show the real expected cash.
                    $live = $item->status === 'open' ? $item->liveTotals() : null;
                    $cash = $live['cash'] ?? (float) $item->cash_sales;
                    $card = $live['card'] ?? (float) $item->card_sales;
                    $room = $live['room_charge'] ?? (float) $item->room_charge_sales;

                    return [
                        'id' => $item->id,
                        'status' => $item->status,
                        'user_id' => $item->user_id,
                        'user_name' => $item->user?->name,
                        'closed_by_name' => $item->closedBy?->name,
                        'opened_at' => $item->opened_at?->toIso8601String(),
                        'closed_at' => $item->closed_at?->toIso8601String(),
                        'opening_float' => (float) $item->opening_float,
                        'expected_cash' => $live ? $item->liveExpectedCash($cash) : (float) $item->expected_cash,
                        'counted_cash' => $item->counted_cash === null ? null : (float) $item->counted_cash,
                        'counted_card' => $item->counted_card === null ? null : (float) $item->counted_card,
                        'card_over_short' => $item->card_over_short === null ? null : (float) $item->card_over_short,
                        'over_short' => $item->over_short === null ? null : (float) $item->over_short,
                        'cash_sales' => $cash,
                        'card_sales' => $card,
                        'room_charge_sales' => $room,
                        'total_sales' => round($cash + $card + $room, 2),
                        'total_orders' => $live ? (int) $item->orders()->where('status', 'completed')->count() : (int) $item->total_orders,
                        // The force-close modal reuses the same Z-report body.
                        'completed_orders' => $live ? (int) $item->orders()->where('status', 'completed')->count() : (int) $item->total_orders,
                        'open_orders' => $live ? (int) $item->orders()->where('status', 'open')->count() : 0,
                        'closing_note' => $item->closing_note,
                        // Foreign drawer lines — live for open shifts so the
                        // (force-)close modal shows real per-currency expected.
                        'currencies' => $item->currencyLines(),
                    ];
                })->values()
            : collect();

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
                // Only cash drives the drawer; card + room_charge are reported
                // separately, and foreign cash lives in its own currency line.
                'expected_cash' => $shift->liveExpectedCash($cash),
                'currencies' => $shift->currencyLines(),
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
        // The active outlet's own warehouse wins the stock display, mirroring
        // the deduction order in InventoryLedger::consumePosOrderItem.
        $outletWarehouse = $currentOutlet?->warehouse_id ? $warehouses->get($currentOutlet->warehouse_id) : null;
        $warehouseStocks = InventoryMovement::query()
            ->selectRaw('warehouse_id, inventory_item_id, SUM(quantity) as quantity')
            ->groupBy('warehouse_id', 'inventory_item_id')->get()
            ->groupBy('warehouse_id')->map(fn ($rows) => $rows->pluck('quantity', 'inventory_item_id'));

        $menu = MenuCategory::with(['items' => fn ($query) => $query
            ->where('is_available', true)->with(['inventoryComponents', 'warehouse'])])
            ->visibleForOutlet($currentOutlet?->id)
            ->orderBy('sort_order')
            ->get()
            ->each(function (MenuCategory $category) use ($salesCounts, $warehouses, $defaultWarehouse, $warehouseStocks, $outletWarehouse) {
                $warehouse = $outletWarehouse
                    ?? $warehouses->get($category->warehouse_id)
                    ?? $warehouses->firstWhere('type', $category->outlet)
                    ?? $defaultWarehouse;
                $category->items->each(function (MenuItem $item) use ($salesCounts, $warehouse, $warehouseStocks, $outletWarehouse) {
                    $itemWarehouse = $outletWarehouse ?? ($item->warehouse?->is_active ? $item->warehouse : $warehouse);
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

        $orders = $query->paginate(15)->withQueryString()->through(fn (PosOrder $order) => [
            'id' => $order->id,
            'reservation_id' => $order->reservation_id,
            'table_number' => $order->table_number,
            'pos_table_id' => $order->pos_table_id,
            'outlet_id' => $order->outlet_id,
            'beach_unit' => $order->beachUnit ? [
                'number' => $order->beachUnit->number,
                'zone_name' => $order->beachUnit->zone?->name,
            ] : null,
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
            'salesperson' => $order->salesperson ? ['id' => $order->salesperson->id, 'name' => $order->salesperson->name] : ($order->createdBy ? ['id' => $order->createdBy->id, 'name' => $order->createdBy->name] : null),
            'cashier' => $order->cashier ? ['id' => $order->cashier->id, 'name' => $order->cashier->name] : null,
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
            'view' => $view,
            'orders' => $orders,
            'menu' => $menu,
            'menuTree' => $this->menuTreePayload($menu),
            'payCurrencies' => CurrencyRates::payable(),
            'activeReservations' => $activeReservations,
            'filters' => $request->only('status', 'order_id', 'outlet'),
            'shiftHistory' => $shiftHistory,
            'currentShift' => $currentShift,
            'canOpenShift' => $request->user()->can('open_pos_shift'),
            'canCloseShift' => $request->user()->can('close_pos_shift'),
            'canCloseAnyShift' => $request->user()->can('close_any_pos_shift'),
            'defaultOpeningFloat' => (float) Setting::get('pos.default_opening_float', 0),
            'receiptSettings' => $this->receiptSettings(),
            'tableContext' => $tableContext,
            'currentSalesperson' => ($salesperson = $this->posSalespeople->current($request))->only(['id', 'name']),
            'salespeople' => $this->posSalespeople->staff()->where('enabled', true)->values(),
            'posSettings' => $posSettings,
            'outlets' => $outlets->map(fn (PosOutlet $outlet) => ['id' => $outlet->id, 'name' => $outlet->name])->values(),
            'currentOutletId' => $currentOutlet?->id,
            'outletCounts' => $this->openOrderCountsByOutlet($outlets),
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

    /**
     * The drill-down skeleton for the sale screen: every tree node whose
     * linked menu group actually offers something, plus all its ancestors so
     * the cashier can navigate down to it. Flat, depth-annotated, roots
     * first, children right below their parent.
     *
     * @return list<array{id:int,name:string,parent_id:?int,depth:int}>
     */
    private function menuTreePayload($menu): array
    {
        $linkedNodeIds = collect($menu)
            ->filter(fn (MenuCategory $group) => $group->inventory_category_id && $group->items->isNotEmpty())
            ->pluck('inventory_category_id')
            ->unique();
        if ($linkedNodeIds->isEmpty()) {
            return [];
        }

        $nodes = InventoryCategory::query()->orderBy('name')->get(['id', 'name', 'parent_id'])->keyBy('id');

        $keep = [];
        foreach ($linkedNodeIds as $nodeId) {
            $node = $nodes->get($nodeId);
            while ($node && ! isset($keep[$node->id])) {
                $keep[$node->id] = true;
                $node = $node->parent_id ? $nodes->get($node->parent_id) : null;
            }
        }

        $byParent = $nodes->filter(fn ($node) => isset($keep[$node->id]))
            ->groupBy(fn ($node) => $node->parent_id ?? 0);
        $flat = [];
        $walk = function (int $parentKey, int $depth) use (&$walk, $byParent, &$flat) {
            foreach ($byParent->get($parentKey, collect()) as $node) {
                $flat[] = ['id' => $node->id, 'name' => $node->name, 'parent_id' => $node->parent_id, 'depth' => $depth];
                $walk($node->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $flat;
    }

    /**
     * The device's active outlet: an explicit ?outlet= switch wins and is
     * remembered in the session; otherwise the remembered one — both checked
     * against the tenant's ACTIVE outlets on every request, so a stale or
     * foreign id silently falls back. Null only when the property has no
     * outlets at all (single-POS behaviour, unchanged).
     */
    private function resolveOutlet(Request $request, $activeOutlets): ?PosOutlet
    {
        if ($activeOutlets->isEmpty()) {
            $request->session()->forget('pos.outlet_id');

            return null;
        }

        $outlet = $request->integer('outlet') ? $activeOutlets->firstWhere('id', $request->integer('outlet')) : null;
        $outlet ??= $activeOutlets->firstWhere('id', (int) $request->session()->get('pos.outlet_id'));
        $outlet ??= $activeOutlets->first();
        $request->session()->put('pos.outlet_id', $outlet->id);

        return $outlet;
    }

    public function store(Request $request): RedirectResponse
    {
        // NOTE (deliberate): items are validated as the tenant's, NOT against the
        // outlet's visible categories — visibility is merchandising filtering for
        // the till; price and stock stay server-side, so a stale tab ordering a
        // hidden item is acceptable.
        $request->validate([
            'table_number' => ['nullable', 'string', 'max:10'],
            'outlet_id' => ['nullable', 'integer', TenantRule::exists('pos_outlets')->where('is_active', true)],
            'reservation_id' => ['nullable', TenantRule::exists('reservations')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', TenantRule::exists('menu_items')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'continue_to_payment' => ['nullable', 'boolean'],
        ]);

        // No order without an open cash-drawer shift for the acting user.
        $shift = PosShift::currentFor(auth()->id());
        if (! $shift) {
            AuditLog::record('pos.shift.blocked', null, ['attempted_action' => 'store']);

            return back()->with('error', 'Hap nje turn para se te krijosh porosi.');
        }

        // Stamp the order with the device's outlet: the validated request value
        // first, else the session's remembered outlet (re-checked as active).
        $outletId = $request->integer('outlet_id') ?: null;
        if (! $outletId && ($remembered = (int) $request->session()->get('pos.outlet_id'))) {
            $outletId = PosOutlet::active()->whereKey($remembered)->value('id');
        }

        $order = DB::transaction(function () use ($request, $shift, $outletId) {
            $order = PosOrder::create([
                'table_number' => $request->table_number,
                'reservation_id' => $request->reservation_id,
                'pos_shift_id' => $shift->id,
                'outlet_id' => $outletId,
                'status' => 'open',
                'created_by' => auth()->id(),
                'salesperson_id' => $this->posSalespeople->current($request)->id,
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

        if ($request->boolean('continue_to_payment')) {
            return redirect()->route('pos.index', ['order_id' => $order->id, 'action' => 'pay'])
                ->with('success', "Porosia #{$order->id} u krijua — vazhdo me pagesën.");
        }

        return back()->with('success', "Porosia #{$order->id} u krijua — ".BaseCurrency::symbol().$order->total_amount);
    }

    public function update(Request $request, PosOrder $posOrder): RedirectResponse
    {
        if ($posOrder->pos_table_id) {
            return redirect()->route('pos.tables', ['table' => $posOrder->pos_table_id])
                ->with('error', 'Porositë e tavolinave ndryshohen duke shtuar një raund të ri.');
        }

        $data = $request->validate([
            'table_number' => ['nullable', 'string', 'max:10'],
            'reservation_id' => ['nullable', TenantRule::exists('reservations')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', TenantRule::exists('menu_items')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'continue_to_payment' => ['nullable', 'boolean'],
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

        if ($request->boolean('continue_to_payment')) {
            return redirect()->route('pos.index', ['order_id' => $posOrder->id, 'action' => 'pay'])
                ->with('success', "Porosia #{$posOrder->id} u përditësua — vazhdo me pagesën.");
        }

        return back()->with('success', "Porosia #{$posOrder->id} u përditësua.");
    }

    public function complete(Request $request, PosOrder $posOrder): RedirectResponse
    {
        // The customer may pay cash/card in any currency the hotel keeps
        // active (plus EUR, the rate base, and the base currency itself).
        $payableCurrencies = array_values(array_unique(array_merge(
            [BaseCurrency::code(), 'EUR'],
            CurrencyRates::enabledCurrencies(),
        )));

        $data = $request->validate([
            'payment_method' => ['nullable', 'in:cash,card,room_charge'],
            'payments' => ['nullable', 'array', 'max:2'],
            'payments.*.method' => ['required_with:payments', 'in:cash,card,room_charge'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01', 'max:99999999.99'],
            'payments.*.currency' => ['nullable', 'string', Rule::in($payableCurrencies)],
            'payments.*.tendered_amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'payments.*.exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:9999999'],
            'reservation_id' => ['nullable', TenantRule::exists('reservations')->where('status', 'checked_in')],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'complimentary' => ['nullable', 'boolean'],
            'return_to' => ['nullable', 'in:tables,beach'],
            'table_id' => ['nullable', 'required_if:return_to,tables', TenantRule::exists('pos_tables')],
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
            if ($order->pos_table_id && $order->rounds()->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages([
                    'order' => 'Dërgo dhe printo të gjitha porositë e padërguara para pagesës.',
                ]);
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

            // Foreign-currency tenders: a manual per-sale rate wins when the
            // till agreed one (frozen on the tender, this invoice only);
            // otherwise the server's live rate is authoritative. The base
            // equivalent is recomputed from what the customer hands over.
            $baseCurrency = BaseCurrency::code();
            $fxTolerance = 0.0;
            $tenders = $tenders->map(function (array $tender) use ($baseCurrency, &$fxTolerance) {
                $currency = strtoupper((string) ($tender['currency'] ?? $baseCurrency));
                if ($currency === $baseCurrency) {
                    $tender['currency'] = null;
                    $tender['tendered_amount'] = null;
                    $tender['exchange_rate'] = null;

                    return $tender;
                }
                if ($tender['method'] === 'room_charge') {
                    throw ValidationException::withMessages(['payments' => 'Pagesa në dhomë regjistrohet vetëm në monedhën bazë.']);
                }
                $manualRate = (float) ($tender['exchange_rate'] ?? 0);
                $rate = $manualRate > 0 ? $manualRate : CurrencyRates::between($currency, $baseCurrency);
                if (! $rate) {
                    throw ValidationException::withMessages(['payments' => "Kursi {$currency}/{$baseCurrency} mungon — vendose manualisht te pagesa ose te Cilësimet → Monedhat."]);
                }
                $tendered = round((float) ($tender['tendered_amount'] ?? $tender['amount']), 2);
                $tender['currency'] = $currency;
                $tender['tendered_amount'] = $tendered;
                $tender['exchange_rate'] = round($rate, 6);
                $tender['amount'] = round($tendered * $rate, 2);
                // Half a minor unit of the tender currency, expressed in base.
                $fxTolerance = max($fxTolerance, 0.005 * $rate);

                return $tender;
            });

            $tenderSum = round((float) $tenders->sum('amount'), 2);
            $residual = round($total - $tenderSum, 2);
            if ($total > 0 && abs($residual) > 0.009 + $fxTolerance) {
                throw ValidationException::withMessages(['payments' => 'Shuma e pagesave duhet të jetë e barabartë me totalin.']);
            }
            // Absorb sub-cent FX rounding into the last foreign tender so the
            // recorded base amounts reconcile exactly with the order total.
            if ($residual !== 0.0 && abs($residual) <= 0.009 + $fxTolerance) {
                $lastForeign = $tenders->search(fn (array $tender) => $tender['currency'] !== null);
                if ($lastForeign !== false) {
                    $tenders = $tenders->map(function (array $tender, int $index) use ($lastForeign, $residual) {
                        if ($index === $lastForeign) {
                            $tender['amount'] = round((float) $tender['amount'] + $residual, 2);
                        }

                        return $tender;
                    });
                } elseif (abs($residual) > 0.009) {
                    throw ValidationException::withMessages(['payments' => 'Shuma e pagesave duhet të jetë e barabartë me totalin.']);
                }
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
                'cashier_id' => $request->user()->id,
            ]);

            foreach ($tenders as $tender) {
                $payment = PosOrderPayment::create([
                    'pos_order_id' => $order->id,
                    'pos_shift_id' => $shift->id,
                    'direction' => 'in',
                    'method' => $tender['method'],
                    'amount' => round((float) $tender['amount'], 2),
                    'currency' => $tender['currency'] ?? null,
                    'tendered_amount' => $tender['tendered_amount'] ?? null,
                    'exchange_rate' => $tender['exchange_rate'] ?? null,
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
        if (($data['return_to'] ?? null) === 'tables') {
            return redirect()->route('pos.tables', ['table' => $data['table_id'] ?? null])
                ->with('success', 'Pagesa u regjistrua dhe tavolina u lirua.');
        }
        if (($data['return_to'] ?? null) === 'beach') {
            return redirect()->route('pos.beach')
                ->with('success', "Porosia #{$posOrder->id} u dorëzua dhe u pagua.");
        }

        return redirect()->route('pos.index', ['order_id' => $posOrder->id, 'action' => 'receipt'])
            ->with('success', 'Pagesa u regjistrua. Fatura mund të printohet ose fiskalizohet veçmas.');
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
                    // A foreign tender is returned from ITS currency account,
                    // at the rate frozen when the sale was taken.
                    'currency' => $salePayment->currency,
                    'tendered_amount' => $salePayment->tendered_amount,
                    'exchange_rate' => $salePayment->exchange_rate,
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

    /**
     * Paneli live i plazhit: porositë e hapura të pikës së plazhit
     * (beach.pos_outlet_id) të grupuara per çadër, që kamarieri t'i shohë
     * dhe t'i mbyllë me një prekje kur i dorëzon.
     */
    public function beachPanel(Request $request): Response
    {
        $outlet = $this->beachOrderingOutlet();
        $shared = [
            'configured' => (bool) $outlet,
            'outletName' => $outlet?->name,
            'hasOpenShift' => (bool) PosShift::currentFor(auth()->id()),
            'canSettle' => $request->user()->can('update_pos_orders'),
            'isAdmin' => $request->user()->hasRole('admin'),
            'currency' => strtoupper((string) ($this->tenantContext->tenant()?->currency ?: 'EUR')),
        ];

        if (! $outlet) {
            return Inertia::render('Pos/BeachPanel', $shared + [
                'groups' => [],
                'forgotten' => [],
                'stats' => ['today_count' => 0, 'today_revenue' => 0.0, 'open_count' => 0],
            ]);
        }

        $open = PosOrder::query()
            ->select('id', 'table_number', 'beach_unit_id', 'outlet_id', 'status', 'total_amount', 'business_date', 'created_at', 'created_by')
            ->where('status', 'open')
            ->where('outlet_id', $outlet->id)
            ->with(['items.menuItem:id,name', 'beachUnit:id,beach_zone_id,number', 'beachUnit.zone:id,name', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $present = fn (PosOrder $order) => [
            'id' => $order->id,
            'unit_number' => $order->beachUnit?->number,
            'zone_name' => $order->beachUnit?->zone?->name,
            'total_amount' => (float) $order->total_amount,
            'created_at' => $order->created_at?->toIso8601String(),
            'business_date' => $order->business_date?->toDateString(),
            'created_by' => $order->createdBy?->name,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->menuItem?->name ?: 'Artikull POS',
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
            ])->values(),
        ];

        // Një porosi ditësh më parë s'është më "në pritje" — është e harruar
        // dhe do vëmendje më vete (mbyllje ose anulim), jo vend në radhë.
        [$forgotten, $fresh] = $open->partition(
            fn (PosOrder $order) => $order->business_date
                ? $order->business_date->lt(today())
                : ($order->created_at && $order->created_at->lt(today()->startOfDay()))
        );

        $groups = $fresh->groupBy(fn (PosOrder $order) => $order->beach_unit_id ?? 0)
            ->map(fn ($orders) => [
                'unit_id' => $orders->first()->beach_unit_id,
                'unit_number' => $orders->first()->beachUnit?->number,
                'zone_name' => $orders->first()->beachUnit?->zone?->name,
                'orders' => $orders->sortByDesc('created_at')->map($present)->values(),
            ])
            ->sortByDesc(fn (array $group) => $group['orders'][0]['created_at'])
            ->values();

        return Inertia::render('Pos/BeachPanel', $shared + [
            'groups' => $groups,
            'forgotten' => $forgotten->sortBy('created_at')->map($present)->values(),
            'stats' => [
                'today_count' => PosOrder::where('outlet_id', $outlet->id)
                    ->where('status', '!=', 'cancelled')
                    ->whereDate('business_date', today())
                    ->count(),
                'today_revenue' => (float) PosOrder::where('outlet_id', $outlet->id)
                    ->where('status', 'completed')
                    ->whereNull('refunded_at')
                    ->whereDate('business_date', today())
                    ->sum('total_amount'),
                'open_count' => $open->count(),
            ],
        ]);
    }

    /**
     * "U dorëzua & u pagua" — mbyllja 1-prekje nga paneli i plazhit: i gjithë
     * totali cash, përmes rrjedhës normale complete() (turni, gardhi i
     * dopio-pagesës, libri i financës, stoku — asgjë e dyfishuar këtu).
     */
    public function beachDeliver(Request $request, PosOrder $posOrder): RedirectResponse
    {
        $outlet = $this->beachOrderingOutlet();
        if (! $outlet || ((int) $posOrder->outlet_id !== $outlet->id && ! $posOrder->beach_unit_id)) {
            return back()->with('error', 'Kjo porosi nuk i përket pikës së plazhit.');
        }
        if ($posOrder->status !== 'open') {
            return back()->with('error', 'Kjo porosi nuk eshte e hapur.');
        }

        $subtotal = round((float) $posOrder->items()->sum('total_price'), 2);
        if ($subtotal === 0.0 && (float) $posOrder->total_amount > 0) {
            $subtotal = round((float) $posOrder->total_amount, 2);
        }
        $discount = (bool) $posOrder->is_complimentary
            ? $subtotal
            : min(round((float) $posOrder->discount_amount, 2), $subtotal);
        $due = round($subtotal - $discount, 2);

        $request->merge([
            'payments' => $due > 0 ? [['method' => 'cash', 'amount' => $due]] : [],
            'payment_method' => null,
            'discount_amount' => $discount > 0 && ! $posOrder->is_complimentary ? $discount : null,
            'discount_reason' => $posOrder->discount_reason,
            'complimentary' => (bool) $posOrder->is_complimentary,
            'reservation_id' => null,
            'return_to' => 'beach',
            'table_id' => null,
        ]);

        return $this->complete($request, $posOrder);
    }

    private function beachOrderingOutlet(): ?PosOutlet
    {
        $outletId = (int) Setting::get('beach.pos_outlet_id', 0);

        return $outletId > 0 ? PosOutlet::active()->find($outletId) : null;
    }

    /**
     * Numëruesit e chips-ave të filtrit te "Porositë": porositë e hapura
     * per pikë + totali (porositë pa pikë hyjnë vetëm te "Të gjitha").
     */
    private function openOrderCountsByOutlet($outlets): array
    {
        if ($outlets->isEmpty()) {
            return ['all' => 0, 'byOutlet' => []];
        }

        $counts = PosOrder::where('status', 'open')
            ->selectRaw('outlet_id, COUNT(*) as total')
            ->groupBy('outlet_id')
            ->pluck('total', 'outlet_id');

        return [
            'all' => (int) $counts->sum(),
            'byOutlet' => $counts->mapWithKeys(fn ($total, $outletId) => [(string) ($outletId ?: 0) => (int) $total])->all(),
        ];
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
