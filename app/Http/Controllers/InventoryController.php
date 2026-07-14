<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\Warehouse;
use App\Services\InventoryLedger;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    public function index(Request $request): Response
    {
        Warehouse::ensureDefault();
        $items = InventoryItem::query()
            ->where('is_active', true)
            ->withSum('movements as stock_quantity', 'quantity')
            ->orderBy('name')
            ->get();
        $lowItems = $items->filter(fn (InventoryItem $item) => (float) $item->minimum_stock > 0
            && (float) ($item->stock_quantity ?? 0) <= (float) $item->minimum_stock);

        return Inertia::render('Inventory/Index', [
            'summary' => [
                'stock_value' => round((float) $items->sum(fn ($item) => max(0, (float) ($item->stock_quantity ?? 0)) * (float) $item->average_cost), 2),
                'active_items' => $items->count(),
                'sale_items' => $items->where('type', 'product')->count(),
                'internal_items' => $items->whereIn('type', ['ingredient', 'consumable'])->count(),
                'warehouse_count' => Warehouse::where('is_active', true)->count(),
                'low_stock_count' => $lowItems->count(),
            ],
            'lowItems' => $lowItems->sortBy(fn ($item) => (float) ($item->stock_quantity ?? 0) - (float) $item->minimum_stock)
                ->take(6)->map(fn ($item) => $this->itemRow($item))->values(),
            'warehouses' => $this->warehouseRows(),
            'recentMovements' => InventoryMovement::query()
                ->with(['item:id,name,unit', 'warehouse:id,name'])
                ->latest('occurred_at')->latest('id')->limit(8)->get()
                ->map(fn (InventoryMovement $movement) => $this->movementRow($movement)),
            'can' => ['manageInventory' => $request->user()->can('manage_inventory')],
        ]);
    }

    public function items(Request $request): Response
    {
        Warehouse::ensureDefault();
        $search = trim((string) $request->input('search', ''));
        $status = in_array($request->input('status'), ['active', 'low', 'inactive'], true)
            ? $request->input('status')
            : 'active';

        $items = InventoryItem::query()
            ->withSum('movements as stock_quantity', 'quantity')
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->get();

        if ($status === 'low') {
            $items = $items->filter(fn ($item) => (float) $item->minimum_stock > 0
                && (float) ($item->stock_quantity ?? 0) <= (float) $item->minimum_stock)->values();
        }

        $stockMap = InventoryMovement::query()
            ->selectRaw('inventory_item_id, warehouse_id, SUM(quantity) as quantity')
            ->groupBy('inventory_item_id', 'warehouse_id')
            ->get()
            ->groupBy('inventory_item_id');
        $warehouseNames = Warehouse::query()->pluck('name', 'id');

        return Inertia::render('Inventory/Items', [
            'items' => $items->map(function (InventoryItem $item) use ($stockMap, $warehouseNames) {
                $row = $this->itemRow($item);
                $row['warehouses'] = collect($stockMap->get($item->id, []))
                    ->filter(fn ($stock) => abs((float) $stock->quantity) > 0.00005)
                    ->map(fn ($stock) => [
                        'id' => $stock->warehouse_id,
                        'name' => $warehouseNames[$stock->warehouse_id] ?? '—',
                        'quantity' => round((float) $stock->quantity, 4),
                    ])->values();

                return $row;
            })->values(),
            'warehouses' => Warehouse::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'filters' => ['search' => $search, 'status' => $status],
            'can' => ['manageInventory' => $request->user()->can('manage_inventory')],
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $data = $this->itemData($request);
        $initialQuantity = (float) ($data['initial_quantity'] ?? 0);
        if ($initialQuantity > 0 && empty($data['initial_warehouse_id'])) {
            throw ValidationException::withMessages(['initial_warehouse_id' => 'Zgjidh magazinën e gjendjes fillestare.']);
        }
        if ($initialQuantity > 0 && $data['type'] === 'service') {
            throw ValidationException::withMessages(['initial_quantity' => 'Shërbimet nuk mbajnë stok.']);
        }

        DB::transaction(function () use ($data, $initialQuantity, $request) {
            $item = InventoryItem::create([
                'name' => trim($data['name']),
                'sku' => mb_strtoupper(trim($data['sku'])),
                'barcode' => ! empty($data['barcode']) ? trim($data['barcode']) : null,
                'category' => ! empty($data['category']) ? trim($data['category']) : null,
                'type' => $data['type'],
                'unit' => $data['unit'],
                'average_cost' => $data['average_cost'] ?? 0,
                'selling_price' => $data['selling_price'] ?? null,
                'minimum_stock' => $data['minimum_stock'] ?? 0,
                'is_active' => true,
            ]);

            if ($initialQuantity > 0) {
                $warehouse = Warehouse::findOrFail($data['initial_warehouse_id']);
                $this->ledger->openingBalance(
                    $item,
                    $warehouse,
                    $initialQuantity,
                    (float) ($data['average_cost'] ?? 0),
                    'Gjendje fillestare gjatë krijimit të artikullit',
                    $request->user()->id,
                );
            }
        });

        return back()->with('success', 'Artikulli u krijua.');
    }

    public function updateItem(Request $request, InventoryItem $item): RedirectResponse
    {
        $data = $this->itemData($request, $item->id, includeInitial: false);
        if ($data['type'] === 'service' && $item->stock() != 0.0) {
            throw ValidationException::withMessages(['type' => 'Artikulli me stok nuk mund të kthehet në shërbim.']);
        }
        $item->update([
            'name' => trim($data['name']),
            'sku' => mb_strtoupper(trim($data['sku'])),
            'barcode' => ! empty($data['barcode']) ? trim($data['barcode']) : null,
            'category' => ! empty($data['category']) ? trim($data['category']) : null,
            'type' => $data['type'],
            'unit' => $data['unit'],
            'selling_price' => $data['selling_price'] ?? null,
            'minimum_stock' => $data['minimum_stock'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return back()->with('success', 'Artikulli u përditësua.');
    }

    public function warehouses(Request $request): Response
    {
        Warehouse::ensureDefault();
        $warehouseStock = InventoryMovement::query()
            ->selectRaw('inventory_item_id, warehouse_id, SUM(quantity) as quantity')
            ->groupBy('inventory_item_id', 'warehouse_id')->get()->groupBy('inventory_item_id');

        return Inertia::render('Inventory/Warehouses', [
            'warehouses' => $this->warehouseRows(),
            'items' => InventoryItem::query()->where('is_active', true)
                ->withSum('movements as stock_quantity', 'quantity')
                ->orderBy('name')->get()->map(function ($item) use ($warehouseStock) {
                    return $this->itemRow($item) + [
                        'warehouse_stock' => collect($warehouseStock->get($item->id, []))
                            ->mapWithKeys(fn ($stock) => [(string) $stock->warehouse_id => round((float) $stock->quantity, 4)])
                            ->all(),
                    ];
                })->values(),
            'recentTransfers' => InventoryTransfer::query()
                ->with(['item:id,name,unit', 'fromWarehouse:id,name', 'toWarehouse:id,name'])
                ->latest('transferred_at')->limit(8)->get()->map(fn ($transfer) => [
                    'id' => $transfer->id,
                    'item' => $transfer->item?->name,
                    'unit' => $transfer->item?->unit,
                    'from' => $transfer->fromWarehouse?->name,
                    'to' => $transfer->toWarehouse?->name,
                    'quantity' => (float) $transfer->quantity,
                    'transferred_at' => $transfer->transferred_at?->toIso8601String(),
                ])->values(),
            'can' => ['manageInventory' => $request->user()->can('manage_inventory')],
        ]);
    }

    public function storeWarehouse(Request $request): RedirectResponse
    {
        $data = $this->warehouseData($request);
        DB::transaction(function () use ($data) {
            if ($data['is_default'] ?? false) {
                $data['is_active'] = true;
                Warehouse::query()->update(['is_default' => false]);
            }
            Warehouse::create($data + ['is_active' => true]);
        });

        return back()->with('success', 'Magazina u krijua.');
    }

    public function updateWarehouse(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $data = $this->warehouseData($request, $warehouse->id);
        DB::transaction(function () use ($data, $warehouse) {
            if ($data['is_default'] ?? false) {
                $data['is_active'] = true;
                Warehouse::query()->where('id', '!=', $warehouse->id)->update(['is_default' => false]);
            } elseif ($warehouse->is_default && (($data['is_default'] ?? true) === false || ($data['is_active'] ?? true) === false)) {
                $replacement = Warehouse::query()->where('id', '!=', $warehouse->id)->where('is_active', true)->first();
                if ($replacement) {
                    $replacement->update(['is_default' => true]);
                } else {
                    $data['is_default'] = true;
                    $data['is_active'] = true;
                }
            }
            $warehouse->update($data);
        });

        return back()->with('success', 'Magazina u përditësua.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'inventory_item_id' => ['required', TenantRule::exists('inventory_items')->where('is_active', true)],
            'from_warehouse_id' => ['required', TenantRule::exists('warehouses')->where('is_active', true)],
            'to_warehouse_id' => ['required', 'different:from_warehouse_id', TenantRule::exists('warehouses')->where('is_active', true)],
            'quantity' => ['required', 'numeric', 'min:0.0001', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $this->ledger->transfer(
            InventoryItem::findOrFail($data['inventory_item_id']),
            Warehouse::findOrFail($data['from_warehouse_id']),
            Warehouse::findOrFail($data['to_warehouse_id']),
            (float) $data['quantity'],
            $data['notes'] ?? null,
            $request->user()->id,
        );

        return back()->with('success', 'Transferimi i stokut u regjistrua.');
    }

    private function itemData(Request $request, ?int $ignoreId = null, bool $includeInitial = true): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['required', 'string', 'max:60', TenantRule::unique('inventory_items', 'sku')->ignore($ignoreId)],
            'barcode' => ['nullable', 'string', 'max:80', TenantRule::unique('inventory_items', 'barcode')->ignore($ignoreId)],
            'category' => ['nullable', 'string', 'max:80'],
            'type' => ['required', Rule::in(['product', 'ingredient', 'consumable', 'service'])],
            'unit' => ['required', Rule::in(['piece', 'kg', 'liter', 'pack'])],
            'selling_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'is_active' => ['nullable', 'boolean'],
        ];
        if ($includeInitial) {
            $rules += [
                'average_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
                'initial_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
                'initial_warehouse_id' => ['nullable', TenantRule::exists('warehouses')->where('is_active', true)],
            ];
        }

        return $request->validate($rules);
    }

    private function warehouseData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', TenantRule::unique('warehouses', 'name')->ignore($ignoreId)],
            'type' => ['required', Rule::in(['central', 'bar', 'restaurant', 'rooms', 'housekeeping', 'other'])],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function itemRow(InventoryItem $item): array
    {
        $stock = array_key_exists('stock_quantity', $item->getAttributes())
            ? round((float) ($item->stock_quantity ?? 0), 4)
            : $item->stock();

        return [
            'id' => $item->id,
            'name' => $item->name,
            'sku' => $item->sku,
            'barcode' => $item->barcode,
            'category' => $item->category,
            'type' => $item->type,
            'unit' => $item->unit,
            'average_cost' => (float) $item->average_cost,
            'selling_price' => $item->selling_price !== null ? (float) $item->selling_price : null,
            'minimum_stock' => (float) $item->minimum_stock,
            'stock' => $stock,
            'stock_value' => round(max(0, $stock) * (float) $item->average_cost, 2),
            'is_low' => (float) $item->minimum_stock > 0 && $stock <= (float) $item->minimum_stock,
            'is_active' => $item->is_active,
        ];
    }

    private function warehouseRows()
    {
        $items = InventoryItem::query()->get(['id', 'average_cost', 'minimum_stock']);
        $itemMap = $items->keyBy('id');
        $stocks = InventoryMovement::query()
            ->selectRaw('warehouse_id, inventory_item_id, SUM(quantity) as quantity')
            ->groupBy('warehouse_id', 'inventory_item_id')->get()->groupBy('warehouse_id');

        return Warehouse::query()->orderByDesc('is_default')->orderBy('name')->get()
            ->map(function (Warehouse $warehouse) use ($stocks, $itemMap) {
                $rows = collect($stocks->get($warehouse->id, []));
                $positive = $rows->filter(fn ($row) => (float) $row->quantity > 0.00005);

                return [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'type' => $warehouse->type,
                    'description' => $warehouse->description,
                    'is_default' => $warehouse->is_default,
                    'is_active' => $warehouse->is_active,
                    'items_count' => $positive->count(),
                    'stock_value' => round((float) $positive->sum(function ($row) use ($itemMap) {
                        return (float) $row->quantity * (float) ($itemMap[$row->inventory_item_id]?->average_cost ?? 0);
                    }), 2),
                    'low_stock_count' => $positive->filter(function ($row) use ($itemMap) {
                        $minimum = (float) ($itemMap[$row->inventory_item_id]?->minimum_stock ?? 0);

                        return $minimum > 0 && (float) $row->quantity <= $minimum;
                    })->count(),
                ];
            })->values();
    }

    private function movementRow(InventoryMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'item' => $movement->item?->name,
            'unit' => $movement->item?->unit,
            'warehouse' => $movement->warehouse?->name,
            'type' => $movement->type,
            'quantity' => (float) $movement->quantity,
            'occurred_at' => $movement->occurred_at?->toIso8601String(),
            'notes' => $movement->notes,
        ];
    }
}
