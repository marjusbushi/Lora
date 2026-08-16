<?php

namespace Tests\Feature;

use App\Models\BeachUnit;
use App\Models\BeachZone;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosOrderPayment;
use App\Models\PosOutlet;
use App\Models\PosShift;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Paneli i plazhit i stafit (pms/pos/beach): grupimi per çadër, statistikat e
 * ditës, seksioni i të harruarave, mbyllja 1-prekje "U dorëzua & u pagua"
 * (cash i plotë përmes complete()), gating + izolimi tenant, dhe filtri i
 * pikës në listën e porosive.
 */
class BeachPanelTest extends TestCase
{
    use RefreshDatabase;

    private BeachUnit $unit;

    private PosOutlet $beachBar;

    private MenuItem $beer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = BeachZone::create(['name' => 'Zona VIP', 'price_per_day' => 800]);
        $this->unit = $zone->units()->create(['number' => '12']);
        $this->beachBar = PosOutlet::create(['name' => 'Beach Bar']);
        Setting::set('beach.pos_outlet_id', $this->beachBar->id, 'number');

        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $this->beer = MenuItem::create(['menu_category_id' => $category->id, 'name' => 'Bire', 'price' => 3.50, 'is_available' => true]);

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeOrder(array $attributes = [], int $quantity = 2): PosOrder
    {
        $order = PosOrder::create(array_merge([
            'status' => 'open',
            'outlet_id' => $this->beachBar->id,
            'beach_unit_id' => $this->unit->id,
            'table_number' => 'Çadra 12',
            'created_by' => $this->admin->id,
            'subtotal_amount' => $quantity * 3.50,
            'total_amount' => $quantity * 3.50,
            'business_date' => today(),
        ], $attributes));

        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'menu_item_id' => $this->beer->id,
            'quantity' => $quantity,
            'unit_price' => 3.50,
            'total_price' => $quantity * 3.50,
        ]);

        return $order;
    }

    private function openShift(): PosShift
    {
        return PosShift::create(['user_id' => $this->admin->id, 'status' => 'open', 'opening_float' => 0, 'opened_at' => now()]);
    }

    public function test_panel_groups_open_orders_by_unit_with_zone_and_a_counter_group(): void
    {
        $this->makeOrder();
        $this->makeOrder([], 1);
        $this->makeOrder(['beach_unit_id' => null, 'table_number' => null], 1);

        // Jashtë panelit: porosia e një pike tjetër dhe një e mbyllur e pikës.
        $restorant = PosOutlet::create(['name' => 'Restorant']);
        $this->makeOrder(['outlet_id' => $restorant->id, 'beach_unit_id' => null], 1);
        $this->makeOrder(['status' => 'completed', 'paid_at' => now()], 1);

        $this->withoutVite();
        $this->actingAs($this->admin)->get(route('pos.beach'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/BeachPanel')
                ->where('configured', true)
                ->where('outletName', 'Beach Bar')
                ->has('groups', 2)
                ->where('groups.0.unit_number', '12')
                ->where('groups.0.zone_name', 'Zona VIP')
                ->has('groups.0.orders', 2)
                ->where('groups.1.unit_number', null)
                ->has('forgotten', 0)
                ->where('stats.open_count', 3));
    }

    public function test_day_stats_count_only_the_beach_outlet_and_todays_business_date(): void
    {
        $this->makeOrder();
        $this->makeOrder(['status' => 'completed', 'paid_at' => now(), 'total_amount' => 10, 'subtotal_amount' => 10], 1);
        $this->makeOrder(['status' => 'cancelled', 'cancelled_at' => now()], 1);

        $this->withoutVite();
        $this->actingAs($this->admin)->get(route('pos.beach'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.today_count', 2)
                ->where('stats.today_revenue', 10)
                ->where('stats.open_count', 1));
    }

    public function test_orders_from_previous_days_fall_into_the_forgotten_section(): void
    {
        $this->makeOrder(['business_date' => today()->subDays(3)]);
        $this->makeOrder([], 1);

        $this->withoutVite();
        $this->actingAs($this->admin)->get(route('pos.beach'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('groups', 1)
                ->has('groups.0.orders', 1)
                ->has('forgotten', 1)
                ->where('forgotten.0.unit_number', '12'));
    }

    public function test_without_a_configured_outlet_the_panel_shows_an_empty_state_not_a_500(): void
    {
        Setting::set('beach.pos_outlet_id', 0, 'number');

        $this->withoutVite();
        $this->actingAs($this->admin)->get(route('pos.beach'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('configured', false)
                ->has('groups', 0)
                ->has('forgotten', 0));
    }

    public function test_disabled_beach_module_and_missing_pos_permission_block_the_panel(): void
    {
        $tenant = Tenant::query()->sole();
        $meta = $tenant->metadata ?? [];
        $meta['billing_access'] = ['status' => 'active', 'modules' => ['beach' => false]];
        $tenant->update(['metadata' => $meta]);

        $this->actingAs($this->admin)->get(route('pos.beach'))->assertStatus(403);

        // Rikthe modulin, hiq rolin: pa view_pos_orders → 403 nga grupi POS.
        $meta['billing_access']['modules']['beach'] = true;
        $tenant->update(['metadata' => $meta]);
        $noRole = User::factory()->create();
        $this->actingAs($noRole)->get(route('pos.beach'))->assertStatus(403);
    }

    public function test_one_tap_deliver_settles_the_full_total_in_cash_inside_the_callers_shift(): void
    {
        $shift = $this->openShift();
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->post(route('pos.beach.deliver', $order))
            ->assertRedirect(route('pos.beach'));

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('cash', $order->payment_method);
        $this->assertNotNull($order->paid_at);
        $this->assertSame($shift->id, $order->pos_shift_id);
        $this->assertSame($this->admin->id, $order->cashier_id);

        $payments = PosOrderPayment::where('pos_order_id', $order->id)->get();
        $this->assertCount(1, $payments);
        $this->assertSame('in', $payments->first()->direction);
        $this->assertSame('cash', $payments->first()->method);
        $this->assertSame(7.0, (float) $payments->first()->amount);
    }

    public function test_delivering_twice_never_creates_a_second_payment(): void
    {
        $this->openShift();
        $order = $this->makeOrder();

        $this->actingAs($this->admin)->post(route('pos.beach.deliver', $order))->assertRedirect(route('pos.beach'));
        $this->actingAs($this->admin)->post(route('pos.beach.deliver', $order))->assertRedirect();

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(1, PosOrderPayment::where('pos_order_id', $order->id)->count());
    }

    public function test_deliver_on_another_tenants_order_is_a_404_and_touches_nothing(): void
    {
        $tenantB = Tenant::factory()->create([
            'status' => 'active',
            'metadata' => ['billing_access' => ['status' => 'active', 'modules' => ['beach' => true, 'pos' => true]]],
        ]);
        TenantDomain::query()->create(['tenant_id' => $tenantB->id, 'domain' => 'beachb.test', 'is_primary' => true]);

        // Porosi e tenantit B, futur direkt (modelet me kontekst A s'e shkruajnë dot).
        $foreignId = DB::table('pos_orders')->insertGetId([
            'tenant_id' => $tenantB->id,
            'status' => 'open',
            'subtotal_amount' => 5,
            'total_amount' => 5,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->openShift();
        $this->actingAs($this->admin)
            ->post("/pms/pos/beach/{$foreignId}/deliver")
            ->assertNotFound();

        $this->assertSame('open', DB::table('pos_orders')->where('id', $foreignId)->value('status'));
        $this->assertSame(0, PosOrderPayment::withoutGlobalScopes()->where('pos_order_id', $foreignId)->count());
    }

    public function test_deliver_requires_an_open_shift(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->post(route('pos.beach.deliver', $order))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame(0, PosOrderPayment::where('pos_order_id', $order->id)->count());
    }

    public function test_deliver_refuses_orders_that_do_not_belong_to_the_beach_outlet(): void
    {
        $this->openShift();
        $restorant = PosOutlet::create(['name' => 'Restorant']);
        $order = $this->makeOrder(['outlet_id' => $restorant->id, 'beach_unit_id' => null]);

        $this->actingAs($this->admin)
            ->post(route('pos.beach.deliver', $order))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('open', $order->fresh()->status);
    }

    public function test_deliver_preserves_an_existing_discount(): void
    {
        $this->openShift();
        $order = $this->makeOrder(['discount_amount' => 2, 'discount_reason' => 'Staf', 'total_amount' => 5]);

        $this->actingAs($this->admin)->post(route('pos.beach.deliver', $order))->assertRedirect(route('pos.beach'));

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame(2.0, (float) $order->discount_amount);
        $this->assertSame(5.0, (float) $order->total_amount);
        $this->assertSame(5.0, (float) PosOrderPayment::where('pos_order_id', $order->id)->value('amount'));
    }

    public function test_orders_list_filters_by_outlet_and_ships_the_beach_unit(): void
    {
        $beachOrder = $this->makeOrder();
        $restorant = PosOutlet::create(['name' => 'Restorant']);
        $older = $this->makeOrder(['outlet_id' => $restorant->id, 'beach_unit_id' => null, 'table_number' => null], 1);
        // Kohë e dallueshme — renditja orderByDesc(created_at) me barazim
        // sekonde është jodeterministe në MySQL (sqlite e fsheh me rowid).
        $older->forceFill(['created_at' => now()->subMinute()])->saveQuietly();

        $this->withoutVite();

        // Pa filtër: të dyja + etiketa e çadrës me zonën (relacioni i ngarkuar).
        $this->actingAs($this->admin)->get(route('pos.orders'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 2)
                ->where('orders.data.0.beach_unit.number', '12')
                ->where('orders.data.0.beach_unit.zone_name', 'Zona VIP')
                ->where('outletCounts.all', 2)
                ->where('outletCounts.byOutlet.'.$this->beachBar->id, 1)
                ->where('outletCounts.byOutlet.'.$restorant->id, 1));

        // Me filtër: vetëm porositë e pikës së kërkuar.
        $this->actingAs($this->admin)->get(route('pos.orders', ['outlet' => $this->beachBar->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $beachOrder->id)
                ->where('filters.outlet', (string) $this->beachBar->id));
    }

    public function test_orders_list_rejects_a_foreign_tenants_outlet_id(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        $foreignOutletId = DB::table('pos_outlets')->insertGetId([
            'tenant_id' => $tenantB->id,
            'name' => 'Pika e huaj',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('pos.orders', ['outlet' => $foreignOutletId]))
            ->assertSessionHasErrors('outlet');
    }
}
