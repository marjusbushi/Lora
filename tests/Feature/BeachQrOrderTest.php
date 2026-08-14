<?php

namespace Tests\Feature;

use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\InventoryItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOutlet;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryLedger;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Beach V2 — ordering from the sunbed QR: menu visibility per outlet, order
 * stamping (outlet + unit + system user + guest token), stock reservation,
 * module gating, tenant isolation, and POS back-compat.
 */
class BeachQrOrderTest extends TestCase
{
    use RefreshDatabase;

    private BeachUnit $unit;

    private PosOutlet $beachBar;

    private MenuItem $beer;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = BeachZone::create(['name' => 'Zona VIP', 'price_per_day' => 800]);
        $this->unit = $zone->units()->create(['number' => '12']);
        $this->beachBar = PosOutlet::create(['name' => 'Beach Bar']);
        Setting::set('beach.pos_outlet_id', $this->beachBar->id, 'number');

        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $this->beer = MenuItem::create(['menu_category_id' => $category->id, 'name' => 'Bire', 'price' => 3.50, 'is_available' => true]);
    }

    private function orderPayload(array $overrides = []): array
    {
        return array_merge(['items' => [['menu_item_id' => $this->beer->id, 'quantity' => 2]]], $overrides);
    }

    public function test_guest_orders_from_qr_and_the_order_is_fully_stamped(): void
    {
        $this->withoutVite();
        $this->get(route('website.beach.qr', $this->unit->qr_token))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Website/BeachOrder')
                ->where('unit.number', '12')
                ->where('outletName', 'Beach Bar'));

        $response = $this->post(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload());
        $response->assertRedirect();

        $order = PosOrder::latest('id')->sole();
        $this->assertSame($this->beachBar->id, $order->outlet_id);
        $this->assertSame($this->unit->id, $order->beach_unit_id);
        $this->assertSame('Çadra 12', $order->table_number);
        $this->assertSame(40, strlen((string) $order->guest_token));
        $this->assertSame('open', $order->status);
        $this->assertSame(7.0, (float) $order->total_amount);
        // Attributed to the tenant's self-seeding system user, never a person.
        $this->assertStringStartsWith('system', (string) $order->createdBy?->email);
        $this->assertNull($order->pos_shift_id);

        // The guest lands on (and can revisit) the status page.
        $this->get(route('website.beach.order.status', $order->guest_token))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Website/BeachOrderStatus')
                ->where('order.unit_number', '12')
                ->where('order.status', 'open')
                ->where('order.total_amount', 7));
    }

    public function test_without_a_configured_outlet_the_qr_keeps_v1_behaviour_and_orders_are_refused(): void
    {
        Setting::set('beach.pos_outlet_id', 0, 'number');

        $this->get(route('website.beach.qr', $this->unit->qr_token))
            ->assertRedirect(route('website.beach'));

        $this->postJson(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload())
            ->assertStatus(422);
        $this->assertSame(0, PosOrder::count());

        // An INACTIVE outlet counts as not configured.
        $this->beachBar->update(['is_active' => false]);
        Setting::set('beach.pos_outlet_id', $this->beachBar->id, 'number');
        $this->get(route('website.beach.qr', $this->unit->qr_token))->assertRedirect(route('website.beach'));
        $this->postJson(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload())
            ->assertStatus(422);
        $this->assertSame(0, PosOrder::count());
    }

    public function test_public_menu_respects_outlet_visibility(): void
    {
        $restorant = PosOutlet::create(['name' => 'Restorant']);
        $onlyRestorant = MenuCategory::create(['name' => 'Ushqim', 'sort_order' => 2]);
        MenuItem::create(['menu_category_id' => $onlyRestorant->id, 'name' => 'Pasta', 'price' => 9, 'is_available' => true]);
        $onlyRestorant->outlets()->sync([$restorant->id]);

        $this->withoutVite();
        $this->get(route('website.beach.qr', $this->unit->qr_token))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('menu', 1)
                ->where('menu.0.name', 'Pije'));
    }

    public function test_insufficient_stock_aborts_the_whole_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $warehouse = Warehouse::create(['name' => 'Magazina Plazh', 'type' => 'bar', 'is_active' => true]);
        $this->beachBar->update(['warehouse_id' => $warehouse->id]);
        $stock = InventoryItem::create(['name' => 'Bire', 'sku' => 'BIRE', 'type' => 'product', 'unit' => 'piece']);
        app(InventoryLedger::class)->openingBalance($stock, $warehouse, 1, 1.0, null, $admin->id);
        $this->beer->inventoryComponents()->create(['inventory_item_id' => $stock->id, 'quantity' => 1]);

        // 2 beers wanted, 1 in stock → refused, NOTHING persisted.
        $this->postJson(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload())
            ->assertStatus(422);
        $this->assertSame(0, PosOrder::count());
        $this->assertSame(1.0, $stock->fresh()->stock($warehouse->id));

        // 1 beer succeeds and reserves the stock from the OUTLET's warehouse.
        $this->post(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload([
            'items' => [['menu_item_id' => $this->beer->id, 'quantity' => 1]],
        ]))->assertRedirect();
        $this->assertSame(1, PosOrder::count());
        $this->assertSame(0.0, $stock->fresh()->stock($warehouse->id));
    }

    public function test_disabled_beach_module_blocks_ordering(): void
    {
        $tenant = Tenant::query()->sole();
        $meta = $tenant->metadata ?? [];
        $meta['billing_access'] = ['status' => 'active', 'modules' => ['beach' => false]];
        $tenant->update(['metadata' => $meta]);

        $this->get(route('website.beach.qr', $this->unit->qr_token))->assertStatus(403);
        $this->postJson(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload())
            ->assertStatus(403);
        $this->assertSame(0, PosOrder::count());
    }

    public function test_tenant_a_token_on_tenant_b_host_is_refused(): void
    {
        $tenantB = Tenant::factory()->create([
            'status' => 'active',
            'metadata' => ['billing_access' => ['status' => 'active', 'modules' => ['beach' => true]]],
        ]);
        TenantDomain::query()->create(['tenant_id' => $tenantB->id, 'domain' => 'beachb.test', 'is_primary' => true]);

        // An order on tenant A first (before any B-host request touches the session).
        $this->post(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload())->assertRedirect();
        $order = PosOrder::latest('id')->sole();

        // Tenant A's sunbed token on tenant B's host: 404 — never a second order.
        $this->get('https://beachb.test/s/'.$this->unit->qr_token)->assertNotFound();
        $this->postJson('https://beachb.test/s/'.$this->unit->qr_token.'/order', $this->orderPayload())
            ->assertNotFound();
        $this->get('https://beachb.test/beach-order/'.$order->guest_token)->assertNotFound();
        $this->assertSame(1, PosOrder::withoutGlobalScopes()->count());
    }

    public function test_wrong_guest_token_is_a_404(): void
    {
        $this->get(route('website.beach.order.status', str_repeat('x', 40)))->assertNotFound();
    }

    public function test_items_hidden_from_the_beach_outlet_cannot_be_ordered_by_id(): void
    {
        $restorant = PosOutlet::create(['name' => 'Restorant']);
        $onlyRestorant = MenuCategory::create(['name' => 'Ushqim', 'sort_order' => 2]);
        $pasta = MenuItem::create(['menu_category_id' => $onlyRestorant->id, 'name' => 'Pasta', 'price' => 9, 'is_available' => true]);
        $onlyRestorant->outlets()->sync([$restorant->id]);

        // The menu hides it — a crafted POST with its id must be refused too.
        $this->postJson(route('website.beach.order.submit', $this->unit->qr_token), [
            'items' => [['menu_item_id' => $pasta->id, 'quantity' => 1]],
        ])->assertStatus(422);
        $this->assertSame(0, PosOrder::count());
    }

    public function test_open_orders_per_sunbed_are_capped_at_three(): void
    {
        foreach (range(1, 3) as $i) {
            $this->post(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload([
                'items' => [['menu_item_id' => $this->beer->id, 'quantity' => 1]],
            ]))->assertRedirect();
        }

        // The 4th open order is refused — a photographed QR cannot flood the POS.
        $this->postJson(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload())
            ->assertStatus(422);
        $this->assertSame(3, PosOrder::count());

        // Completing one frees a slot.
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        \App\Models\PosShift::create(['user_id' => $admin->id, 'status' => 'open', 'opening_float' => 0, 'opened_at' => now()]);
        $this->actingAs($admin)->post(route('pos.complete', PosOrder::first()), ['payment_method' => 'cash'])->assertRedirect();

        $this->post(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload([
            'items' => [['menu_item_id' => $this->beer->id, 'quantity' => 1]],
        ]))->assertRedirect();
        $this->assertSame(4, PosOrder::count());
    }

    public function test_duplicate_menu_item_lines_are_refused(): void
    {
        $this->postJson(route('website.beach.order.submit', $this->unit->qr_token), [
            'items' => [
                ['menu_item_id' => $this->beer->id, 'quantity' => 20],
                ['menu_item_id' => $this->beer->id, 'quantity' => 20],
            ],
        ])->assertStatus(422);
        $this->assertSame(0, PosOrder::count());
    }

    public function test_the_order_appears_on_the_staff_pos_open_orders(): void
    {
        $this->post(route('website.beach.order.submit', $this->unit->qr_token), $this->orderPayload())->assertRedirect();

        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->withoutVite();
        $this->actingAs($admin)->get(route('pos.orders'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.table_number', 'Çadra 12')
                ->where('orders.data.0.status', 'open')
                ->where('orders.data.0.total_amount', 7));
    }

    public function test_normal_pos_flow_is_untouched_by_the_beach_columns(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        \App\Models\PosShift::create(['user_id' => $admin->id, 'status' => 'open', 'opening_float' => 0, 'opened_at' => now()]);

        $this->actingAs($admin)->post(route('pos.store'), [
            'items' => [['menu_item_id' => $this->beer->id, 'quantity' => 1]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = PosOrder::latest('id')->sole();
        $this->assertNull($order->beach_unit_id);
        $this->assertNull($order->guest_token);
    }
}
