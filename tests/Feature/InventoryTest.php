<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_item_opening_balance_creates_auditable_stock(): void
    {
        $admin = $this->role('admin');
        $warehouse = Warehouse::ensureDefault();

        $this->actingAs($admin)->post(route('inventory.items.store'), [
            'name' => 'Ujë 0.5L', 'sku' => 'UJE-05', 'type' => 'product', 'unit' => 'piece',
            'average_cost' => 0.25, 'selling_price' => 1.20, 'minimum_stock' => 12,
            'initial_quantity' => 30, 'initial_warehouse_id' => $warehouse->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item = InventoryItem::where('sku', 'UJE-05')->firstOrFail();
        $this->assertSame(30.0, $item->stock($warehouse->id));
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'type' => 'opening_balance', 'quantity' => 30,
        ]);
    }

    public function test_existing_bill_flow_can_receive_inventory_lines(): void
    {
        $admin = $this->role('admin');
        $warehouse = Warehouse::ensureDefault();
        $supplier = Supplier::create(['name' => 'Eco Market', 'category' => 'Ushqim & Pije']);
        $item = InventoryItem::create([
            'name' => 'Kafe kokërr', 'sku' => 'KAFE-1KG', 'type' => 'ingredient', 'unit' => 'kg',
            'average_cost' => 8, 'minimum_stock' => 5, 'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('finance.bills.store'), [
            'supplier_id' => $supplier->id, 'number' => 'BL-100', 'category' => 'Ushqim & Pije',
            'issue_date' => '2026-07-14', 'due_date' => '2026-07-21', 'currency' => 'EUR',
            'fx_rate' => null, 'total' => 1, 'receive_stock' => true,
            'items' => [[
                'inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id,
                'quantity' => 10, 'unit_cost' => 9.5,
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $bill = Bill::where('number', 'BL-100')->firstOrFail();
        $line = BillItem::where('bill_id', $bill->id)->firstOrFail();
        $this->assertSame(95.0, (float) $bill->total);
        $this->assertNotNull($line->received_at);
        $this->assertSame(10.0, $item->fresh()->stock($warehouse->id));
        $this->assertSame(9.5, (float) $item->fresh()->average_cost);
    }

    public function test_product_photo_and_sales_channels_publish_controlled_catalog_items(): void
    {
        Storage::fake('public');
        $admin = $this->role('admin');
        $central = Warehouse::ensureDefault();
        $bar = Warehouse::create(['name' => 'Magazina Bar', 'type' => 'bar', 'is_active' => true]);
        $rooms = Warehouse::create(['name' => 'Magazina Dhoma', 'type' => 'rooms', 'is_active' => true]);
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1, 'outlet' => 'bar']);

        $this->actingAs($admin)->post(route('inventory.items.store'), [
            'name' => 'Coca-Cola 330ml', 'sku' => 'COLA-330', 'type' => 'product', 'unit' => 'piece',
            'image' => UploadedFile::fake()->image('cola.jpg', 600, 600),
            'average_cost' => 0.8, 'selling_price' => 3, 'minimum_stock' => 5,
            'sell_in_pos' => true, 'pos_menu_category_id' => $category->id, 'pos_warehouse_id' => $bar->id,
            'sell_in_rooms' => true, 'room_selling_price' => 4, 'room_warehouse_id' => $rooms->id,
            'initial_quantity' => 10, 'initial_warehouse_id' => $central->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item = InventoryItem::where('sku', 'COLA-330')->firstOrFail();
        $menuItem = MenuItem::where('inventory_item_id', $item->id)->firstOrFail();
        Storage::disk('public')->assertExists($item->image_path);
        $this->assertTrue($item->sell_in_pos);
        $this->assertTrue($item->sell_in_rooms);
        $this->assertSame(4.0, (float) $item->room_selling_price);
        $this->assertSame($category->id, $menuItem->menu_category_id);
        $this->assertSame($bar->id, $menuItem->warehouse_id);
        $this->assertSame($item->image_path, $menuItem->image_path);
        $this->assertDatabaseHas('menu_item_inventory', [
            'menu_item_id' => $menuItem->id, 'inventory_item_id' => $item->id, 'quantity' => 1,
        ]);

        $this->actingAs($admin)->put(route('inventory.items.update', $item), [
            'name' => $item->name, 'sku' => $item->sku, 'type' => 'product', 'unit' => 'piece',
            'selling_price' => 3, 'minimum_stock' => 5,
            'sell_in_pos' => false, 'sell_in_rooms' => true,
            'room_selling_price' => 4, 'room_warehouse_id' => $rooms->id, 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($menuItem->fresh()->is_available);
    }

    public function test_receiving_the_same_bill_line_is_idempotent(): void
    {
        $warehouse = Warehouse::ensureDefault();
        $supplier = Supplier::create(['name' => 'Supplier']);
        $item = InventoryItem::create(['name' => 'Peshqir', 'sku' => 'PESH-1', 'type' => 'consumable', 'unit' => 'piece']);
        $bill = Bill::create([
            'supplier_id' => $supplier->id, 'category' => 'Pajisje', 'issue_date' => '2026-07-14',
            'currency' => 'EUR', 'fx_rate' => 1, 'total' => 20, 'status' => 'open',
        ]);
        $line = BillItem::create([
            'bill_id' => $bill->id, 'inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'description' => $item->name, 'quantity' => 4, 'unit' => 'piece', 'unit_cost' => 5, 'line_total' => 20,
        ]);

        $ledger = app(InventoryLedger::class);
        $ledger->receiveBillItem($line);
        $ledger->receiveBillItem($line->fresh());

        $this->assertSame(1, InventoryMovement::where('sourceable_id', $line->id)->where('type', 'purchase')->count());
        $this->assertSame(4.0, $item->fresh()->stock($warehouse->id));
    }

    public function test_bill_stock_can_be_received_later(): void
    {
        $admin = $this->role('admin');
        $warehouse = Warehouse::ensureDefault();
        $supplier = Supplier::create(['name' => 'Furnitori']);
        $item = InventoryItem::create(['name' => 'Shampo', 'sku' => 'SHAMPO', 'type' => 'consumable', 'unit' => 'piece']);
        $bill = Bill::create([
            'supplier_id' => $supplier->id, 'category' => 'Pajisje', 'issue_date' => '2026-07-14',
            'currency' => 'EUR', 'fx_rate' => 1, 'total' => 12, 'status' => 'open',
        ]);
        $line = BillItem::create([
            'bill_id' => $bill->id, 'inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'description' => $item->name, 'quantity' => 6, 'unit' => 'piece', 'unit_cost' => 2, 'line_total' => 12,
        ]);

        $this->assertSame(0.0, $item->stock());
        $this->actingAs($admin)->post(route('finance.bills.receive', $bill))->assertRedirect();
        $this->assertNotNull($line->fresh()->received_at);
        $this->assertSame(6.0, $item->fresh()->stock($warehouse->id));
    }

    public function test_transfer_moves_location_without_changing_total_stock(): void
    {
        $admin = $this->role('admin');
        $central = Warehouse::ensureDefault();
        $bar = Warehouse::create(['name' => 'Magazina Bar', 'type' => 'bar', 'is_active' => true]);
        $item = InventoryItem::create(['name' => 'Ujë', 'sku' => 'UJE', 'type' => 'product', 'unit' => 'piece']);
        app(InventoryLedger::class)->openingBalance($item, $central, 20, 0.3, null, $admin->id);

        $this->actingAs($admin)->post(route('inventory.transfers.store'), [
            'inventory_item_id' => $item->id, 'from_warehouse_id' => $central->id,
            'to_warehouse_id' => $bar->id, 'quantity' => 6,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(14.0, $item->fresh()->stock($central->id));
        $this->assertSame(6.0, $item->fresh()->stock($bar->id));
        $this->assertSame(20.0, $item->fresh()->stock());
    }

    public function test_receptionist_cannot_open_inventory(): void
    {
        $receptionist = $this->role('receptionist');
        $this->actingAs($receptionist)->get(route('inventory.index'))->assertForbidden();
    }
}
