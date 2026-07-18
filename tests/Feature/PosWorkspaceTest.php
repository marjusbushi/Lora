<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosShift;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PosWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_menu_exposes_recent_completed_sales_for_frequent_items(): void
    {
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $espresso = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Espresso',
            'price' => 1.5,
            'is_available' => true,
        ]);
        $water = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Uje',
            'price' => 1,
            'is_available' => true,
        ]);

        $completed = PosOrder::create([
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 6,
            'created_by' => $admin->id,
        ]);
        PosOrderItem::create([
            'pos_order_id' => $completed->id,
            'menu_item_id' => $espresso->id,
            'quantity' => 4,
            'unit_price' => 1.5,
            'total_price' => 6,
        ]);

        $open = PosOrder::create([
            'status' => 'open',
            'total_amount' => 20,
            'created_by' => $admin->id,
        ]);
        PosOrderItem::create([
            'pos_order_id' => $open->id,
            'menu_item_id' => $water->id,
            'quantity' => 20,
            'unit_price' => 1,
            'total_price' => 20,
        ]);

        $this->actingAs($admin)->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->where('menu.0.items.0.name', 'Espresso')
                ->where('menu.0.items.0.sales_count', 4)
                ->where('menu.0.items.1.name', 'Uje')
                ->where('menu.0.items.1.sales_count', 0));
    }

    public function test_pos_submodules_expose_their_operational_views(): void
    {
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $open = PosOrder::create(['status' => 'open', 'total_amount' => 8, 'created_by' => $admin->id]);
        $completed = PosOrder::create(['status' => 'completed', 'payment_method' => 'cash', 'total_amount' => 12, 'created_by' => $admin->id]);

        PosShift::create([
            'user_id' => $admin->id,
            'status' => 'closed',
            'opening_float' => 20,
            'opened_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
            'expected_cash' => 32,
            'counted_cash' => 32,
            'over_short' => 0,
            'total_sales' => 12,
            'total_orders' => 1,
        ]);

        $this->actingAs($admin)->get(route('pos.orders'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->where('view', 'orders')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $open->id));

        $this->actingAs($admin)->get(route('pos.receipts'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('view', 'receipts')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $completed->id));

        $this->actingAs($admin)->get(route('pos.shifts'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('view', 'shifts')
                ->has('shiftHistory', 1)
                ->where('shiftHistory.0.user_name', $admin->name));
    }

    public function test_creating_an_order_returns_the_id_for_same_screen_payment(): void
    {
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $item = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Ujë',
            'price' => 2,
            'is_available' => true,
        ]);
        PosShift::create([
            'user_id' => $admin->id,
            'status' => 'open',
            'opening_float' => 0,
            'opened_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('pos.store'), [
            'items' => [['menu_item_id' => $item->id, 'quantity' => 2]],
            'continue_to_payment' => true,
        ]);

        $order = PosOrder::latest('id')->firstOrFail();
        $response->assertRedirect(route('pos.index', ['order_id' => $order->id, 'action' => 'pay']));
        $this->assertSame('open', $order->status);
        $this->assertSame('4.00', $order->total_amount);
    }

    public function test_pos_registers_expose_paginated_results(): void
    {
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        foreach (range(1, 16) as $index) {
            PosOrder::create([
                'status' => 'open',
                'total_amount' => $index,
                'created_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)->get(route('pos.orders'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('view', 'orders')
                ->where('orders.current_page', 1)
                ->where('orders.last_page', 2)
                ->where('orders.total', 16)
                ->has('orders.data', 15));

        $this->actingAs($admin)->get(route('pos.orders', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.current_page', 2)
                ->has('orders.data', 1));
    }
}
