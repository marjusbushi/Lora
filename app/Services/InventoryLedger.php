<?php

namespace App\Services;

use App\Models\BillItem;
use App\Models\FolioItem;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\PosOrderItem;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryLedger
{
    public function receiveBillItem(BillItem $billItem, ?int $userId = null): InventoryMovement
    {
        return DB::transaction(function () use ($billItem, $userId) {
            $billItem->loadMissing(['item', 'bill.supplier']);
            if (! $billItem->warehouse_id) {
                throw ValidationException::withMessages(['warehouse_id' => 'Zgjidh magazinën ku do të hyjë stoku.']);
            }
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($billItem->inventory_item_id);

            $existing = InventoryMovement::query()
                ->where('sourceable_type', BillItem::class)
                ->where('sourceable_id', $billItem->id)
                ->where('type', 'purchase')
                ->first();

            if ($existing) {
                return $existing;
            }

            $quantity = (float) $billItem->quantity;
            $unitCost = $this->baseUnitCost($billItem);
            $stockBefore = $item->stock();
            $valueBefore = $stockBefore * (float) $item->average_cost;
            $stockAfter = $stockBefore + $quantity;

            if ($stockAfter > 0) {
                $item->update(['average_cost' => round(($valueBefore + $quantity * $unitCost) / $stockAfter, 4)]);
            }

            $movement = InventoryMovement::create([
                'inventory_item_id' => $item->id,
                'warehouse_id' => $billItem->warehouse_id,
                'type' => 'purchase',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'sourceable_type' => BillItem::class,
                'sourceable_id' => $billItem->id,
                'notes' => 'Bill '.($billItem->bill->number ?: '#'.$billItem->bill_id)
                    .' · '.($billItem->bill->supplier?->name ?? ''),
                'occurred_at' => $billItem->bill->issue_date?->startOfDay() ?? now(),
                'created_by' => $userId,
            ]);

            $billItem->update(['received_at' => now()]);

            return $movement;
        });
    }

    public function openingBalance(
        InventoryItem $item,
        Warehouse $warehouse,
        float $quantity,
        float $unitCost,
        ?string $notes,
        ?int $userId,
    ): InventoryMovement {
        return DB::transaction(function () use ($item, $warehouse, $quantity, $unitCost, $notes, $userId) {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($quantity > 0 && $unitCost >= 0) {
                $locked->update(['average_cost' => round($unitCost, 4)]);
            }

            return InventoryMovement::create([
                'inventory_item_id' => $locked->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'opening_balance',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'notes' => $notes ?: 'Gjendje fillestare',
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);
        });
    }

    public function transfer(
        InventoryItem $item,
        Warehouse $from,
        Warehouse $to,
        float $quantity,
        ?string $notes,
        ?int $userId,
    ): InventoryTransfer {
        return DB::transaction(function () use ($item, $from, $to, $quantity, $notes, $userId) {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            $available = $locked->stock($from->id);
            if ($available + 0.00005 < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Sasia e kërkuar kalon stokun e disponueshëm ('.$available.').',
                ]);
            }

            $transfer = InventoryTransfer::create([
                'inventory_item_id' => $locked->id,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'quantity' => $quantity,
                'notes' => $notes,
                'transferred_at' => now(),
                'created_by' => $userId,
            ]);

            foreach ([[$from, -$quantity, 'transfer_out'], [$to, $quantity, 'transfer_in']] as [$warehouse, $signed, $type]) {
                InventoryMovement::create([
                    'inventory_item_id' => $locked->id,
                    'warehouse_id' => $warehouse->id,
                    'type' => $type,
                    'quantity' => $signed,
                    'unit_cost' => (float) $locked->average_cost,
                    'sourceable_type' => InventoryTransfer::class,
                    'sourceable_id' => $transfer->id,
                    'notes' => $notes ?: "Transferim {$from->name} → {$to->name}",
                    'occurred_at' => $transfer->transferred_at,
                    'created_by' => $userId,
                ]);
            }

            return $transfer;
        });
    }

    public function consumePosOrderItem(PosOrderItem $orderItem, ?int $userId = null): void
    {
        DB::transaction(function () use ($orderItem, $userId) {
            $orderItem->loadMissing([
                'order',
                'menuItem.category.warehouse',
                'menuItem.warehouse',
                'menuItem.inventoryComponents.inventoryItem',
            ]);

            $category = $orderItem->menuItem?->category;
            $warehouse = $orderItem->menuItem?->warehouse?->is_active
                ? $orderItem->menuItem->warehouse
                : null;
            $warehouse ??= $category?->warehouse?->is_active ? $category->warehouse : null;
            $warehouse ??= Warehouse::query()->where('is_active', true)
                ->when($category?->outlet, fn ($query, $outlet) => $query->where('type', $outlet))
                ->first();
            $warehouse ??= Warehouse::ensureDefault();

            foreach ($orderItem->menuItem?->inventoryComponents ?? [] as $component) {
                $item = InventoryItem::query()->lockForUpdate()->findOrFail($component->inventory_item_id);
                $existing = InventoryMovement::query()
                    ->where('sourceable_type', PosOrderItem::class)
                    ->where('sourceable_id', $orderItem->id)
                    ->where('type', 'sale')
                    ->where('warehouse_id', $warehouse->id)
                    ->where('inventory_item_id', $item->id)
                    ->exists();
                if ($existing) {
                    continue;
                }

                $required = (float) $orderItem->quantity * (float) $component->quantity;
                $available = $item->stock($warehouse->id);
                if ($available + 0.00005 < $required) {
                    throw ValidationException::withMessages([
                        'inventory' => "Stok i pamjaftueshëm për {$item->name}. Disponueshëm: {$available} {$item->unit}.",
                    ]);
                }

                InventoryMovement::create([
                    'inventory_item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'type' => 'sale',
                    'quantity' => -$required,
                    'unit_cost' => (float) $item->average_cost,
                    'sourceable_type' => PosOrderItem::class,
                    'sourceable_id' => $orderItem->id,
                    'notes' => 'POS Porosi #'.$orderItem->pos_order_id.' · '.($orderItem->menuItem?->name ?? ''),
                    'occurred_at' => $orderItem->order?->paid_at ?? now(),
                    'created_by' => $userId,
                ]);
            }
        });
    }

    /** Release a reserved/sold POS recipe on edit, cancellation or full refund. */
    public function releasePosOrderItem(PosOrderItem $orderItem, string $type, ?int $userId = null): void
    {
        DB::transaction(function () use ($orderItem, $type, $userId) {
            if (! in_array($type, ['sale_release', 'sale_return'], true)) {
                throw new \InvalidArgumentException('Invalid POS inventory reversal type.');
            }

            $sales = InventoryMovement::query()
                ->where('sourceable_type', PosOrderItem::class)
                ->where('sourceable_id', $orderItem->id)
                ->where('type', 'sale')
                ->get();

            foreach ($sales as $sale) {
                InventoryMovement::firstOrCreate(
                    [
                        'sourceable_type' => PosOrderItem::class,
                        'sourceable_id' => $orderItem->id,
                        'type' => $type,
                        'warehouse_id' => $sale->warehouse_id,
                        'inventory_item_id' => $sale->inventory_item_id,
                    ],
                    [
                        'quantity' => abs((float) $sale->quantity),
                        'unit_cost' => $sale->unit_cost,
                        'notes' => ($type === 'sale_return' ? 'Kthim POS' : 'Lirim rezervimi POS')
                            .' · Porosia #'.$orderItem->pos_order_id,
                        'occurred_at' => now(),
                        'created_by' => $userId,
                    ],
                );
            }
        });
    }

    public function consumeFolioItem(FolioItem $folioItem, ?int $userId = null): InventoryMovement
    {
        return DB::transaction(function () use ($folioItem, $userId) {
            $folioItem->loadMissing(['inventoryItem', 'warehouse', 'reservation.room']);
            if (! $folioItem->inventory_item_id || ! $folioItem->warehouse_id) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => 'Artikulli dhe magazina janë të detyrueshme për konsumin e minibar-it.',
                ]);
            }

            $item = InventoryItem::query()->lockForUpdate()->findOrFail($folioItem->inventory_item_id);
            $existing = InventoryMovement::query()
                ->where('sourceable_type', FolioItem::class)
                ->where('sourceable_id', $folioItem->id)
                ->where('type', 'room_charge')
                ->where('warehouse_id', $folioItem->warehouse_id)
                ->where('inventory_item_id', $item->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            $quantity = (float) $folioItem->inventory_quantity;
            $available = $item->stock($folioItem->warehouse_id);
            if ($quantity <= 0 || $available + 0.00005 < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Stok i pamjaftueshëm për {$item->name}. Disponueshëm: {$available} {$item->unit}.",
                ]);
            }

            return InventoryMovement::create([
                'inventory_item_id' => $item->id,
                'warehouse_id' => $folioItem->warehouse_id,
                'type' => 'room_charge',
                'quantity' => -$quantity,
                'unit_cost' => (float) $item->average_cost,
                'sourceable_type' => FolioItem::class,
                'sourceable_id' => $folioItem->id,
                'notes' => 'Minibar · Rezervimi #'.$folioItem->reservation_id
                    .' · Dhoma '.($folioItem->reservation?->room?->room_number ?? '—')
                    .' · '.$item->name,
                'occurred_at' => $folioItem->charge_date?->startOfDay() ?? now(),
                'created_by' => $userId,
            ]);
        });
    }

    private function baseUnitCost(BillItem $billItem): float
    {
        $unitCost = (float) $billItem->unit_cost;
        if (strtoupper((string) $billItem->bill->currency) === BaseCurrency::code()) {
            return round($unitCost, 4);
        }

        return round($unitCost / max(0.0001, (float) $billItem->bill->fx_rate), 4);
    }
}
