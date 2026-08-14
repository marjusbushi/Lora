<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosOutlet;
use App\Models\PosShift;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryLedger;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Sales outlets (Restorant / Bar / Beach Bar): outlet CRUD, menu visibility,
 * order stamping, per-outlet stock routing, report filtering, tenant
 * isolation, and the no-outlets back-compat guarantee.
 */
class PosOutletTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function outlet(string $name, array $attributes = []): PosOutlet
    {
        return PosOutlet::create(array_merge(['name' => $name], $attributes));
    }

    private function openShiftFor(User $user): PosShift
    {
        return PosShift::create(['user_id' => $user->id, 'status' => 'open', 'opening_float' => 0, 'opened_at' => now()]);
    }

    private function menuItemIn(MenuCategory $category, string $name = 'Artikull', float $price = 5): MenuItem
    {
        return MenuItem::create(['menu_category_id' => $category->id, 'name' => $name, 'price' => $price, 'is_available' => true]);
    }

    public function test_admin_creates_outlets_and_pos_index_lists_only_active_ones(): void
    {
        $admin = $this->admin();

        foreach (['Restorant', 'Bar', 'Beach Bar'] as $name) {
            $this->actingAs($admin)->post(route('settings.pos.outlets.store'), ['name' => $name])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $bar = PosOutlet::where('name', 'Bar')->sole();
        $this->actingAs($admin)->put(route('settings.pos.outlets.update', $bar), ['name' => 'Bar', 'is_active' => false])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->withoutVite();
        $this->actingAs($admin)->get(route('pos.index', ['direct' => 1]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('outlets', 2)
                ->where('outlets.0.name', 'Restorant')
                ->where('outlets.1.name', 'Beach Bar')
                ->where('currentOutletId', PosOutlet::where('name', 'Restorant')->sole()->id));

        // Duplicate name is refused per tenant.
        $this->actingAs($admin)->post(route('settings.pos.outlets.store'), ['name' => 'Restorant'])
            ->assertSessionHasErrors('name');
    }

    public function test_category_without_pivot_is_visible_everywhere_and_restriction_filters_the_menu(): void
    {
        $admin = $this->admin();
        $restorant = $this->outlet('Restorant');
        $bar = $this->outlet('Bar');

        $everywhere = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $onlyBar = MenuCategory::create(['name' => 'Kokteje', 'sort_order' => 2]);
        $this->menuItemIn($everywhere, 'Uje');
        $this->menuItemIn($onlyBar, 'Mojito');

        $this->actingAs($admin)->put(route('settings.menu-categories.update', $onlyBar), [
            'name' => 'Kokteje', 'outlet_ids' => [$bar->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->withoutVite();
        $this->actingAs($admin)->get(route('pos.index', ['direct' => 1, 'outlet' => $restorant->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('menu', 1)
                ->where('menu.0.name', 'Pije'));

        $this->actingAs($admin)->get(route('pos.index', ['direct' => 1, 'outlet' => $bar->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('menu', 2));

        // Selecting EVERY outlet normalizes back to "visible everywhere" (empty pivot),
        // so outlets added later inherit the category automatically.
        $this->actingAs($admin)->put(route('settings.menu-categories.update', $onlyBar), [
            'name' => 'Kokteje', 'outlet_ids' => [$restorant->id, $bar->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('menu_category_pos_outlet', 0);
    }

    public function test_order_is_stamped_with_outlet_and_report_filters_per_outlet(): void
    {
        $admin = $this->admin();
        $bar = $this->outlet('Bar');
        $beach = $this->outlet('Beach Bar');
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $item = $this->menuItemIn($category, 'Bire', 3.50);
        $this->openShiftFor($admin);

        $this->actingAs($admin)->post(route('pos.store'), [
            'outlet_id' => $beach->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 2]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = PosOrder::latest('id')->sole();
        $this->assertSame($beach->id, $order->outlet_id);

        $this->actingAs($admin)->post(route('pos.complete', $order), ['payment_method' => 'cash'])
            ->assertRedirect()->assertSessionHasNoErrors();

        // An inactive or foreign outlet id on order creation is a validation error, not a 500.
        $inactive = $this->outlet('I fikur', ['is_active' => false]);
        $this->actingAs($admin)->post(route('pos.store'), [
            'outlet_id' => $inactive->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('outlet_id');

        $this->withoutVite();
        $this->actingAs($admin)->get(route('reports.posSales', ['outlet' => $beach->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total_revenue', 7)
                ->where('summary.order_count', 1));

        $this->actingAs($admin)->get(route('reports.posSales', ['outlet' => $bar->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total_revenue', 0)
                ->where('summary.order_count', 0));

        // A nonexistent/foreign outlet id is a validation error (TenantRule) — the
        // same rule that refuses another tenant's outlet id when a context exists.
        $this->actingAs($admin)->get(route('reports.posSales', ['outlet' => 999999]))
            ->assertSessionHasErrors('outlet');

        // Unfiltered: the by-outlet summary rows add up to the grand total.
        $this->actingAs($admin)->get(route('reports.posSales'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total_revenue', 7)
                ->has('byOutlet', 1)
                ->where('byOutlet.0.name', 'Beach Bar')
                ->where('byOutlet.0.orders', 1)
                ->where('byOutlet.0.revenue', 7));
    }

    public function test_outlet_warehouse_wins_stock_deduction_and_outletless_orders_keep_the_old_chain(): void
    {
        $admin = $this->admin();
        Warehouse::ensureDefault();
        $categoryWarehouse = Warehouse::create(['name' => 'Magazina Bar', 'type' => 'bar', 'is_active' => true]);
        $beachWarehouse = Warehouse::create(['name' => 'Magazina Plazh', 'type' => 'bar', 'is_active' => true]);
        $beach = $this->outlet('Beach Bar', ['warehouse_id' => $beachWarehouse->id]);

        $beer = InventoryItem::create(['name' => 'Bire', 'sku' => 'BIRE', 'type' => 'product', 'unit' => 'piece']);
        app(InventoryLedger::class)->openingBalance($beer, $categoryWarehouse, 10, 1.0, null, $admin->id);
        app(InventoryLedger::class)->openingBalance($beer, $beachWarehouse, 10, 1.0, null, $admin->id);

        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1, 'warehouse_id' => $categoryWarehouse->id]);
        $item = $this->menuItemIn($category, 'Bire', 3.50);
        $item->inventoryComponents()->create(['inventory_item_id' => $beer->id, 'quantity' => 1]);
        $shift = $this->openShiftFor($admin);

        // Order stamped with the beach outlet → its own warehouse is consumed.
        $stamped = PosOrder::create(['status' => 'open', 'total_amount' => 0, 'created_by' => $admin->id, 'pos_shift_id' => $shift->id, 'outlet_id' => $beach->id]);
        $stampedLine = PosOrderItem::create(['pos_order_id' => $stamped->id, 'menu_item_id' => $item->id, 'quantity' => 2, 'unit_price' => 3.50, 'total_price' => 7.0]);
        app(InventoryLedger::class)->consumePosOrderItem($stampedLine, $admin->id);
        $this->assertSame(8.0, $beer->fresh()->stock($beachWarehouse->id));
        $this->assertSame(10.0, $beer->fresh()->stock($categoryWarehouse->id));

        // Outlet-less order → exactly today's chain (the category warehouse).
        $legacy = PosOrder::create(['status' => 'open', 'total_amount' => 0, 'created_by' => $admin->id, 'pos_shift_id' => $shift->id]);
        $legacyLine = PosOrderItem::create(['pos_order_id' => $legacy->id, 'menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 3.50, 'total_price' => 3.50]);
        app(InventoryLedger::class)->consumePosOrderItem($legacyLine, $admin->id);
        $this->assertSame(9.0, $beer->fresh()->stock($categoryWarehouse->id));
        $this->assertSame(8.0, $beer->fresh()->stock($beachWarehouse->id));
    }

    public function test_reassigning_the_outlet_warehouse_mid_ticket_does_not_deduct_stock_twice(): void
    {
        $admin = $this->admin();
        Warehouse::ensureDefault();
        $first = Warehouse::create(['name' => 'Magazina A', 'type' => 'bar', 'is_active' => true]);
        $second = Warehouse::create(['name' => 'Magazina B', 'type' => 'bar', 'is_active' => true]);
        $beach = $this->outlet('Beach Bar', ['warehouse_id' => $first->id]);

        $beer = InventoryItem::create(['name' => 'Bire', 'sku' => 'BIRE', 'type' => 'product', 'unit' => 'piece']);
        app(InventoryLedger::class)->openingBalance($beer, $first, 10, 1.0, null, $admin->id);
        app(InventoryLedger::class)->openingBalance($beer, $second, 10, 1.0, null, $admin->id);

        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $item = $this->menuItemIn($category, 'Bire', 3.50);
        $item->inventoryComponents()->create(['inventory_item_id' => $beer->id, 'quantity' => 1]);
        $this->openShiftFor($admin);

        // store() reserves stock from warehouse A…
        $this->actingAs($admin)->post(route('pos.store'), [
            'outlet_id' => $beach->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 2]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(8.0, $beer->fresh()->stock($first->id));

        // …the admin re-points the outlet at warehouse B while the ticket is open…
        $this->actingAs($admin)->put(route('settings.pos.outlets.update', $beach), [
            'name' => 'Beach Bar', 'warehouse_id' => $second->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        // …and completing must NOT consume a second time from warehouse B.
        $order = PosOrder::latest('id')->sole();
        $this->actingAs($admin)->post(route('pos.complete', $order), ['payment_method' => 'cash'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(8.0, $beer->fresh()->stock($first->id));
        $this->assertSame(10.0, $beer->fresh()->stock($second->id));
    }

    public function test_outlet_with_open_orders_cannot_be_deactivated(): void
    {
        $admin = $this->admin();
        $beach = $this->outlet('Beach Bar');
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $item = $this->menuItemIn($category, 'Bire', 3.50);
        $this->openShiftFor($admin);

        $this->actingAs($admin)->post(route('pos.store'), [
            'outlet_id' => $beach->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Open ticket → deactivation refused (its tables would leave every POS view).
        $this->actingAs($admin)->put(route('settings.pos.outlets.update', $beach), [
            'name' => 'Beach Bar', 'is_active' => false,
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertTrue($beach->fresh()->is_active);

        // Paid ticket → deactivation is allowed.
        $order = PosOrder::latest('id')->sole();
        $this->actingAs($admin)->post(route('pos.complete', $order), ['payment_method' => 'cash'])->assertRedirect();
        $this->actingAs($admin)->put(route('settings.pos.outlets.update', $beach), [
            'name' => 'Beach Bar', 'is_active' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($beach->fresh()->is_active);
    }

    public function test_tenant_b_is_refused_on_every_operation_over_tenant_a_outlets(): void
    {
        $admin = $this->admin();
        $tenantA = Tenant::query()->sole();
        $outletA = $this->outlet('Beach Bar');
        $categoryA = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $itemA = $this->menuItemIn($categoryA);

        $tenantB = Tenant::factory()->create();
        app(TenantRoleService::class)->provision($tenantB);
        $context = app(TenantContext::class);
        $context->set($tenantB);
        $adminB = User::factory()->create(['current_tenant_id' => $tenantB->id]);
        $adminB->tenants()->syncWithoutDetaching([$tenantB->id => ['is_owner' => true, 'is_active' => true]]);
        $adminB->unsetRelation('roles')->assignRole('admin');
        $itemB = null;
        $shiftB = PosShift::create(['user_id' => $adminB->id, 'status' => 'open', 'opening_float' => 0, 'opened_at' => now()]);
        $categoryB = MenuCategory::create(['name' => 'Pije B', 'sort_order' => 1]);
        $itemB = MenuItem::create(['menu_category_id' => $categoryB->id, 'name' => 'Uje B', 'price' => 1, 'is_available' => true]);
        $context->clear();

        // Update / delete over tenant A's outlet: 404, never success.
        $this->actingAs($adminB)->put(route('settings.pos.outlets.update', $outletA), ['name' => 'Hacked'])
            ->assertNotFound();
        $this->actingAs($adminB)->delete(route('settings.pos.outlets.destroy', $outletA))
            ->assertNotFound();

        // Order stamped with tenant A's outlet id: refused fail-closed (with no
        // resolvable tenant, B's team-scoped role grants nothing → 403 before
        // validation; with a context, TenantRule refuses the foreign id — the
        // in-tenant variant is asserted in the stamping test).
        $response = $this->actingAs($adminB)->post(route('pos.store'), [
            'outlet_id' => $outletA->id,
            'items' => [['menu_item_id' => $itemB->id, 'quantity' => 1]],
        ]);
        $this->assertTrue(
            $response->isClientError() || session('errors')?->has('outlet_id'),
            "Pritej refuzim (4xx ose gabim validimi), erdhi {$response->getStatusCode()}",
        );
        $this->assertDatabaseMissing('pos_orders', ['outlet_id' => $outletA->id]);

        // Report filter with tenant A's outlet id: refused fail-closed. (With no
        // resolvable tenant for B the module middleware 404s before validation —
        // either way, never tenant A's data.)
        $this->actingAs($adminB)->get(route('reports.posSales', ['outlet' => $outletA->id]))
            ->assertNotFound();

        // Listing: tenant B never sees tenant A's outlets — with no resolvable
        // tenant the whole PMS surface is fail-closed (404), and with one, the
        // global tenant scope pins every PosOutlet query to B.
        $this->withoutVite();
        $this->actingAs($adminB)->get(route('pos.index', ['direct' => 1]))->assertNotFound();
        $context->set($tenantB);
        $this->assertSame(0, PosOutlet::count());

        $context->set($tenantA);
        $this->assertSame('Beach Bar', $outletA->fresh()->name);
        $this->assertTrue($outletA->fresh()->is_active);
    }

    public function test_without_any_outlet_pos_orders_and_reports_behave_exactly_as_before(): void
    {
        $admin = $this->admin();
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $item = $this->menuItemIn($category, 'Uje', 1.0);
        $this->openShiftFor($admin);

        $this->withoutVite();
        $this->actingAs($admin)->get(route('pos.index', ['direct' => 1]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('outlets', 0)
                ->where('currentOutletId', null)
                ->has('menu', 1));

        $this->actingAs($admin)->post(route('pos.store'), [
            'items' => [['menu_item_id' => $item->id, 'quantity' => 3]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = PosOrder::latest('id')->sole();
        $this->assertNull($order->outlet_id);

        $this->actingAs($admin)->post(route('pos.complete', $order), ['payment_method' => 'cash'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('reports.posSales'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total_revenue', 3)
                ->where('summary.order_count', 1)
                ->has('outlets', 0));
    }

    public function test_outlet_with_orders_deactivates_instead_of_deleting_and_history_keeps_its_name(): void
    {
        $admin = $this->admin();
        $beach = $this->outlet('Beach Bar');
        $unused = $this->outlet('Pa perdorur');
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $item = $this->menuItemIn($category, 'Bire', 3.50);
        $this->openShiftFor($admin);

        $this->actingAs($admin)->post(route('pos.store'), [
            'outlet_id' => $beach->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $order = PosOrder::latest('id')->sole();
        $this->actingAs($admin)->post(route('pos.complete', $order), ['payment_method' => 'cash'])->assertRedirect();

        // Used outlet: refused delete → deactivated with an explanatory flash error.
        $this->actingAs($admin)->delete(route('settings.pos.outlets.destroy', $beach))
            ->assertRedirect()->assertSessionHas('error');
        $beach->refresh();
        $this->assertFalse($beach->is_active);
        $this->assertSame($beach->id, $order->fresh()->outlet_id);

        // Deactivated outlet leaves the POS picker but the report history keeps the stamp.
        $this->withoutVite();
        $this->actingAs($admin)->get(route('pos.index', ['direct' => 1]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('outlets', 1));
        $this->actingAs($admin)->get(route('reports.posSales'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('byOutlet.0.name', 'Beach Bar')
                ->where('byOutlet.0.revenue', 3.5));

        // Unused outlet deletes cleanly.
        $this->actingAs($admin)->delete(route('settings.pos.outlets.destroy', $unused))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('pos_outlets', ['id' => $unused->id]);
    }
}
