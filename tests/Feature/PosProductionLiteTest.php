<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\InventoryItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosOrderPayment;
use App\Models\PosShift;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PosProductionLiteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PosShift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->shift = PosShift::create([
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_float' => 20,
            'opened_at' => now(),
        ]);
    }

    private function menuItem(float $price = 100): MenuItem
    {
        $category = MenuCategory::create(['name' => 'Bar', 'sort_order' => 1]);

        return MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Produkt test',
            'price' => $price,
            'is_available' => true,
        ]);
    }

    private function openOrder(MenuItem $item, int $quantity = 1): PosOrder
    {
        $order = PosOrder::create([
            'status' => 'open',
            'total_amount' => $item->price * $quantity,
            'subtotal_amount' => $item->price * $quantity,
            'created_by' => $this->admin->id,
            'pos_shift_id' => $this->shift->id,
        ]);
        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'menu_item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => $item->price,
            'total_price' => $item->price * $quantity,
        ]);

        return $order;
    }

    public function test_split_payment_discount_posts_cash_to_arka_and_card_to_bank(): void
    {
        $order = $this->openOrder($this->menuItem());

        $this->actingAs($this->admin)->post(route('pos.complete', $order), [
            'payments' => [
                ['method' => 'cash', 'amount' => 40],
                ['method' => 'card', 'amount' => 50],
            ],
            'discount_amount' => 10,
            'discount_reason' => 'Klient besnik',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(90.0, (float) $order->total_amount);
        $this->assertSame(10.0, (float) $order->discount_amount);
        $this->assertNull($order->payment_method);
        $this->assertSame(now()->toDateString(), $order->business_date->toDateString());
        $this->assertDatabaseCount('pos_order_payments', 2);
        $this->assertSame(40.0, FinanceAccount::where('type', 'cash')->firstOrFail()->balance());
        $this->assertSame(50.0, FinanceAccount::where('type', 'bank')->firstOrFail()->balance());
    }

    public function test_refund_reverses_each_tender_and_finance_balance(): void
    {
        $order = $this->openOrder($this->menuItem());
        $this->actingAs($this->admin)->post(route('pos.complete', $order), [
            'payments' => [
                ['method' => 'cash', 'amount' => 30],
                ['method' => 'card', 'amount' => 70],
            ],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('pos.refund', $order), [
            'reason' => 'Porosi e kthyer nga klienti',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($order->fresh()->refunded_at);
        $this->assertSame(2, PosOrderPayment::where('pos_order_id', $order->id)->where('direction', 'out')->count());
        $this->assertSame(0.0, FinanceAccount::where('type', 'cash')->firstOrFail()->balance());
        $this->assertSame(0.0, FinanceAccount::where('type', 'bank')->firstOrFail()->balance());
    }

    public function test_open_order_reserves_stock_edit_reconciles_it_and_cancel_releases_it(): void
    {
        $warehouse = Warehouse::ensureDefault();
        $stock = InventoryItem::create(['name' => 'Cola', 'sku' => 'COLA-T', 'type' => 'product', 'unit' => 'piece']);
        app(InventoryLedger::class)->openingBalance($stock, $warehouse, 5, 1, null, $this->admin->id);
        $menu = $this->menuItem(3);
        $menu->update(['warehouse_id' => $warehouse->id]);
        $menu->inventoryComponents()->create(['inventory_item_id' => $stock->id, 'quantity' => 1]);

        $this->actingAs($this->admin)->post(route('pos.store'), [
            'items' => [['menu_item_id' => $menu->id, 'quantity' => 2]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $order = PosOrder::latest('id')->firstOrFail();
        $this->assertSame(3.0, $stock->fresh()->stock($warehouse->id));

        $this->actingAs($this->admin)->put(route('pos.update', $order), [
            'items' => [['menu_item_id' => $menu->id, 'quantity' => 3]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2.0, $stock->fresh()->stock($warehouse->id));

        $this->actingAs($this->admin)->post(route('pos.cancel', $order), [
            'reason' => 'Klienti ndryshoi mendje',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(5.0, $stock->fresh()->stock($warehouse->id));
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_shift_cannot_close_while_it_has_open_orders(): void
    {
        $this->openOrder($this->menuItem());

        $this->actingAs($this->admin)->post(route('pos.shift.close', $this->shift), [
            'counted_cash' => 20,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame('open', $this->shift->fresh()->status);
    }

    public function test_complimentary_order_requires_reason_and_creates_no_money_movement(): void
    {
        $order = $this->openOrder($this->menuItem());

        $this->actingAs($this->admin)->post(route('pos.complete', $order), [
            'complimentary' => true,
            'discount_reason' => 'Kompliment nga menaxheri',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertTrue($order->is_complimentary);
        $this->assertSame(0.0, (float) $order->total_amount);
        $this->assertDatabaseCount('pos_order_payments', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_pos_sales_report_uses_business_date_not_creation_date(): void
    {
        $order = $this->openOrder($this->menuItem(25));
        $order->forceFill([
            'status' => 'completed',
            'payment_method' => 'cash',
            'paid_at' => '2026-07-18 09:00:00',
            'business_date' => '2026-07-18',
            'created_at' => '2026-06-01 09:00:00',
        ])->save();
        $this->assertSame(1, PosOrder::whereDate('business_date', '2026-07-18')->count());

        $this->actingAs($this->admin)->get(route('reports.posSales', ['from' => '2026-07-18', 'to' => '2026-07-18']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/PosSales')
                ->where('summary.order_count', 1)
                ->where('summary.total_revenue', 25));
    }

    public function test_discount_is_allocated_to_category_and_item_revenue(): void
    {
        $order = $this->openOrder($this->menuItem(100));
        $this->actingAs($this->admin)->post(route('pos.complete', $order), [
            'payment_method' => 'cash',
            'discount_amount' => 20,
            'discount_reason' => 'Ofertë promocionale',
        ])->assertSessionHasNoErrors();

        $date = now()->toDateString();
        $this->actingAs($this->admin)->get(route('reports.posSales', ['from' => $date, 'to' => $date]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total_revenue', 80)
                ->where('byCategory.0.revenue', 80)
                ->where('topItems.0.revenue', 80));
    }

    public function test_legacy_orders_remain_in_payment_reports_without_tender_rows(): void
    {
        $order = $this->openOrder($this->menuItem(55));
        $order->forceFill([
            'status' => 'completed',
            'payment_method' => 'cash',
            'paid_at' => '2026-07-17 12:00:00',
            'business_date' => '2026-07-17',
        ])->save();

        $this->actingAs($this->admin)->get(route('reports.payments', ['from' => '2026-07-17', 'to' => '2026-07-17']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('totals.pos_total', 55)
                ->where('totals.cash', 55));

        $this->actingAs($this->admin)->get(route('reports.posPaymentMix', ['from' => '2026-07-17', 'to' => '2026-07-17']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.grand_total', 55)
                ->where('summary.order_count', 1)
                ->where('rows.0.method', 'cash'));
    }

    public function test_mixed_version_shift_posts_legacy_cash_and_new_tenders_once(): void
    {
        $legacy = $this->openOrder($this->menuItem(100));
        $legacy->update([
            'status' => 'completed',
            'payment_method' => 'cash',
            'paid_at' => now(),
            'business_date' => today(),
        ]);

        $new = $this->openOrder($this->menuItem(50));
        $this->actingAs($this->admin)->post(route('pos.complete', $new), [
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('pos.shift.close', $this->shift), [
            'counted_cash' => 170,
        ])->assertSessionHasNoErrors();

        $this->assertSame(150.0, FinanceAccount::where('type', 'cash')->firstOrFail()->balance());
        $this->assertSame(0.0, (float) $this->shift->fresh()->over_short);
        $this->assertDatabaseCount('finance_payments', 2);
    }

    public function test_void_report_keeps_legacy_cancellations_without_cancelled_at(): void
    {
        $order = $this->openOrder($this->menuItem(25));
        $order->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => null,
            'created_at' => '2026-07-16 12:00:00',
        ])->save();

        $this->actingAs($this->admin)->get(route('reports.posVoids', ['from' => '2026-07-16', 'to' => '2026-07-16']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.count', 1)
                ->where('summary.total', 25)
                ->where('rows.0.created_at', '16/07 12:00'));
    }
}
