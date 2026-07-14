<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosShift;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PosInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_completing_pos_order_consumes_recipe_from_category_warehouse(): void
    {
        $admin = $this->admin();
        $central = Warehouse::ensureDefault();
        $bar = Warehouse::create(['name' => 'Magazina Bar', 'type' => 'bar', 'is_active' => true]);
        $water = InventoryItem::create(['name' => 'Ujë 0.5L', 'sku' => 'UJE-05', 'type' => 'product', 'unit' => 'piece']);
        $lemon = InventoryItem::create(['name' => 'Limon', 'sku' => 'LIMON', 'type' => 'ingredient', 'unit' => 'kg']);
        app(InventoryLedger::class)->openingBalance($water, $bar, 20, 0.30, null, $admin->id);
        app(InventoryLedger::class)->openingBalance($lemon, $bar, 2, 2.00, null, $admin->id);

        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1, 'outlet' => 'bar', 'warehouse_id' => $bar->id]);
        $menuItem = MenuItem::create(['menu_category_id' => $category->id, 'name' => 'Ujë me limon', 'price' => 2, 'is_available' => true]);
        $menuItem->inventoryComponents()->createMany([
            ['inventory_item_id' => $water->id, 'quantity' => 1],
            ['inventory_item_id' => $lemon->id, 'quantity' => 0.05],
        ]);
        $shift = PosShift::create(['user_id' => $admin->id, 'status' => 'open', 'opening_float' => 0, 'opened_at' => now()]);
        $order = PosOrder::create(['status' => 'open', 'total_amount' => 6, 'created_by' => $admin->id, 'pos_shift_id' => $shift->id]);
        $orderItem = PosOrderItem::create([
            'pos_order_id' => $order->id, 'menu_item_id' => $menuItem->id,
            'quantity' => 3, 'unit_price' => 2, 'total_price' => 6,
        ]);

        $this->actingAs($admin)->post(route('pos.complete', $order), ['payment_method' => 'cash'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(17.0, $water->fresh()->stock($bar->id));
        $this->assertSame(1.85, $lemon->fresh()->stock($bar->id));
        $this->assertSame(0.0, $water->fresh()->stock($central->id));
        $this->assertDatabaseHas('inventory_movements', [
            'sourceable_type' => PosOrderItem::class, 'sourceable_id' => $orderItem->id,
            'inventory_item_id' => $water->id, 'warehouse_id' => $bar->id, 'type' => 'sale', 'quantity' => -3,
        ]);
        $this->withoutVite();
        $this->actingAs($admin)->get(route('pos.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('menu.0.items.0.inventory_tracked', true)
                ->where('menu.0.items.0.available_portions', 17));
    }

    public function test_pos_consumption_is_idempotent_and_untracked_items_do_not_move_stock(): void
    {
        $admin = $this->admin();
        $warehouse = Warehouse::ensureDefault();
        $stockItem = InventoryItem::create(['name' => 'Kafe', 'sku' => 'KAFE', 'type' => 'ingredient', 'unit' => 'kg']);
        app(InventoryLedger::class)->openingBalance($stockItem, $warehouse, 5, 10, null, $admin->id);
        $category = MenuCategory::create(['name' => 'Kafe', 'sort_order' => 1, 'warehouse_id' => $warehouse->id]);
        $tracked = MenuItem::create(['menu_category_id' => $category->id, 'name' => 'Espresso', 'price' => 1.5, 'is_available' => true]);
        $tracked->inventoryComponents()->create(['inventory_item_id' => $stockItem->id, 'quantity' => 0.01]);
        $untracked = MenuItem::create(['menu_category_id' => $category->id, 'name' => 'Shërbim', 'price' => 1, 'is_available' => true]);
        $order = PosOrder::create(['status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 4, 'paid_at' => now(), 'created_by' => $admin->id]);
        $trackedLine = PosOrderItem::create(['pos_order_id' => $order->id, 'menu_item_id' => $tracked->id, 'quantity' => 2, 'unit_price' => 1.5, 'total_price' => 3]);
        $untrackedLine = PosOrderItem::create(['pos_order_id' => $order->id, 'menu_item_id' => $untracked->id, 'quantity' => 1, 'unit_price' => 1, 'total_price' => 1]);

        $ledger = app(InventoryLedger::class);
        $ledger->consumePosOrderItem($trackedLine, $admin->id);
        $ledger->consumePosOrderItem($trackedLine->fresh(), $admin->id);
        $ledger->consumePosOrderItem($untrackedLine, $admin->id);

        $this->assertSame(4.98, $stockItem->fresh()->stock($warehouse->id));
        $this->assertSame(1, InventoryMovement::where('sourceable_type', PosOrderItem::class)
            ->where('sourceable_id', $trackedLine->id)->count());
        $this->assertSame(0, InventoryMovement::where('sourceable_type', PosOrderItem::class)
            ->where('sourceable_id', $untrackedLine->id)->count());
    }

    public function test_menu_settings_save_warehouse_and_inventory_recipe(): void
    {
        $admin = $this->admin();
        $warehouse = Warehouse::ensureDefault();
        $inventoryItem = InventoryItem::create(['name' => 'Birrë', 'sku' => 'BIRRE', 'type' => 'product', 'unit' => 'piece']);

        $this->actingAs($admin)->post(route('settings.menu-categories.store'), [
            'name' => 'Bar', 'outlet' => 'bar', 'warehouse_id' => $warehouse->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $category = MenuCategory::where('name', 'Bar')->firstOrFail();

        $this->actingAs($admin)->post(route('settings.menu-items.store'), [
            'menu_category_id' => $category->id, 'name' => 'Birrë 0.33', 'price' => 3,
            'inventory_components' => [[
                'inventory_item_id' => $inventoryItem->id, 'quantity' => 1,
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $menuItem = MenuItem::where('name', 'Birrë 0.33')->firstOrFail();
        $this->assertSame($warehouse->id, $category->fresh()->warehouse_id);
        $this->assertDatabaseHas('menu_item_inventory', [
            'menu_item_id' => $menuItem->id, 'inventory_item_id' => $inventoryItem->id, 'quantity' => 1,
        ]);
    }

    public function test_pos_completion_is_rolled_back_when_recipe_stock_is_insufficient(): void
    {
        $admin = $this->admin();
        $warehouse = Warehouse::ensureDefault();
        $inventoryItem = InventoryItem::create(['name' => 'Lëng', 'sku' => 'LENG', 'type' => 'product', 'unit' => 'piece']);
        app(InventoryLedger::class)->openingBalance($inventoryItem, $warehouse, 1, 1, null, $admin->id);
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1, 'warehouse_id' => $warehouse->id]);
        $menuItem = MenuItem::create(['menu_category_id' => $category->id, 'name' => 'Lëng', 'price' => 2, 'is_available' => true]);
        $menuItem->inventoryComponents()->create(['inventory_item_id' => $inventoryItem->id, 'quantity' => 1]);
        $shift = PosShift::create(['user_id' => $admin->id, 'status' => 'open', 'opening_float' => 0, 'opened_at' => now()]);
        $order = PosOrder::create(['status' => 'open', 'total_amount' => 4, 'created_by' => $admin->id, 'pos_shift_id' => $shift->id]);
        PosOrderItem::create(['pos_order_id' => $order->id, 'menu_item_id' => $menuItem->id, 'quantity' => 2, 'unit_price' => 2, 'total_price' => 4]);

        $this->actingAs($admin)->post(route('pos.complete', $order), ['payment_method' => 'cash'])
            ->assertSessionHasErrors('inventory');

        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame(1.0, $inventoryItem->fresh()->stock($warehouse->id));
        $this->assertSame(0, InventoryMovement::where('type', 'sale')->count());
    }
}
