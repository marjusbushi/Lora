<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderRound;
use App\Models\PosShift;
use App\Models\PosTable;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PosTableServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MenuItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        PosShift::create([
            'user_id' => $this->admin->id,
            'status' => 'open',
            'opening_float' => 0,
            'opened_at' => now(),
        ]);
        $category = MenuCategory::create(['name' => 'Pije', 'sort_order' => 1]);
        $this->item = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Kafe',
            'price' => 1.5,
            'is_available' => true,
        ]);
    }

    public function test_direct_mode_redirects_new_sales_but_still_opens_a_deep_linked_table(): void
    {
        Setting::set('pos.service_mode', 'direct', 'text');

        // Plain visits route to the sale screen — direct-mode hotels do not
        // work with the table map.
        $this->actingAs($this->admin)->get(route('pos.tables'))
            ->assertRedirect(route('pos.index', ['direct' => 1]));

        // But a legacy TABLE order from before the mode switch must remain
        // reachable (Paguaj deep-links here), or it can never be settled.
        $table = PosTable::create(['number' => 5, 'name' => 'Tavolina 5', 'area' => 'Salla', 'seats' => 4, 'sort_order' => 1, 'is_active' => true]);
        PosOrder::create(['status' => 'open', 'pos_table_id' => $table->id, 'total_amount' => 10, 'created_by' => $this->admin->id]);

        $this->actingAs($this->admin)->get(route('pos.tables', ['table' => $table->id, 'action' => 'pay']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Pos/Tables'));
    }

    public function test_table_workspace_creates_ten_default_tables(): void
    {
        $this->actingAs($this->admin)->get(route('pos.tables'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Tables')
                ->has('tables', 10)
                ->where('stats.total', 10)
                ->where('tables.0.status', 'free'));

        $this->assertDatabaseCount('pos_tables', 10);
    }

    public function test_selected_table_opens_the_existing_pos_sale_workspace(): void
    {
        $this->actingAs($this->admin)->get(route('pos.tables'))->assertOk();
        $table = PosTable::firstOrFail();

        $this->actingAs($this->admin)->get(route('pos.index', ['table' => $table->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->where('view', 'sale')
                ->where('tableContext.id', $table->id)
                ->where('tableContext.name', $table->name)
                ->where('tableContext.number', $table->number));
    }

    public function test_sent_round_opens_table_account_and_preserves_round_trace(): void
    {
        $this->actingAs($this->admin)->get(route('pos.tables'));
        $table = PosTable::firstOrFail();

        $this->actingAs($this->admin)->post(route('pos.tables.rounds.store', $table), [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2]],
            'covers' => 3,
            'send' => true,
        ])->assertRedirect(route('pos.tables', ['table' => $table->id]))
            ->assertSessionHasNoErrors();

        $order = PosOrder::firstOrFail();
        $round = PosOrderRound::firstOrFail();
        $this->assertSame($table->id, $order->pos_table_id);
        $this->assertSame('1', (string) $round->sequence);
        $this->assertSame('sent', $round->status);
        $this->assertNotNull($round->printed_at);
        $this->assertSame(3.0, (float) $order->total_amount);
        $this->assertSame(3, $order->covers);
        $this->assertSame($this->admin->id, $order->salesperson_id);
        $this->assertDatabaseHas('pos_order_items', [
            'pos_order_id' => $order->id,
            'pos_order_round_id' => $round->id,
            'quantity' => 2,
        ]);
    }

    public function test_draft_round_can_be_sent_without_creating_a_second_round(): void
    {
        $this->actingAs($this->admin)->get(route('pos.tables'));
        $table = PosTable::firstOrFail();
        $this->actingAs($this->admin)->post(route('pos.tables.rounds.store', $table), [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
            'send' => false,
        ])->assertSessionHasNoErrors();

        $round = PosOrderRound::firstOrFail();
        $this->assertSame('draft', $round->status);

        $this->actingAs($this->admin)->post(route('pos.rounds.send', $round))
            ->assertSessionHasNoErrors();

        $this->assertSame('sent', $round->fresh()->status);
        $this->assertDatabaseCount('pos_order_rounds', 1);
    }

    public function test_generic_order_editor_cannot_flatten_table_rounds(): void
    {
        $this->actingAs($this->admin)->get(route('pos.tables'));
        $table = PosTable::firstOrFail();
        $this->actingAs($this->admin)->post(route('pos.tables.rounds.store', $table), [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2]],
            'send' => true,
        ]);
        $order = PosOrder::firstOrFail();

        $this->actingAs($this->admin)->put(route('pos.update', $order), [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 9]],
        ])->assertRedirect(route('pos.tables', ['table' => $table->id]));

        $this->assertSame(2, $order->items()->firstOrFail()->quantity);
        $this->assertDatabaseCount('pos_order_rounds', 1);
    }

    public function test_table_account_can_move_and_be_freed_after_payment(): void
    {
        $this->actingAs($this->admin)->get(route('pos.tables'));
        [$source, $destination] = PosTable::take(2)->get();
        $this->actingAs($this->admin)->post(route('pos.tables.rounds.store', $source), [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2]],
            'send' => true,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('pos.tables.transfer', $source), [
            'destination_table_id' => $destination->id,
        ])->assertSessionHasNoErrors();

        $order = PosOrder::firstOrFail();
        $this->assertSame($destination->id, $order->fresh()->pos_table_id);

        $this->actingAs($this->admin)->post(route('pos.complete', $order), [
            'payment_method' => 'cash',
            'return_to' => 'tables',
            'table_id' => $destination->id,
        ])->assertRedirect(route('pos.tables', ['table' => $destination->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($this->admin->id, $order->fresh()->cashier_id);
        $this->actingAs($this->admin)->get(route('pos.tables', ['table' => $destination->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tables.1.status', 'free'));
    }

    public function test_table_account_cannot_move_to_an_occupied_table(): void
    {
        $this->actingAs($this->admin)->get(route('pos.tables'));
        [$source, $destination] = PosTable::take(2)->get();

        foreach ([$source, $destination] as $table) {
            $this->actingAs($this->admin)->post(route('pos.tables.rounds.store', $table), [
                'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
                'send' => true,
            ])->assertSessionHasNoErrors();
        }

        $sourceOrder = PosOrder::where('pos_table_id', $source->id)->firstOrFail();
        $this->actingAs($this->admin)->post(route('pos.tables.transfer', $source), [
            'destination_table_id' => $destination->id,
        ])->assertSessionHasErrors('destination_table_id');

        $this->assertSame($source->id, $sourceOrder->fresh()->pos_table_id);
    }

    public function test_table_account_with_a_draft_round_cannot_be_paid(): void
    {
        $this->actingAs($this->admin)->get(route('pos.tables'));
        $table = PosTable::firstOrFail();
        $this->actingAs($this->admin)->post(route('pos.tables.rounds.store', $table), [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
            'send' => false,
        ])->assertSessionHasNoErrors();

        $order = PosOrder::firstOrFail();
        $this->actingAs($this->admin)->post(route('pos.complete', $order), [
            'payment_method' => 'cash',
            'return_to' => 'tables',
            'table_id' => $table->id,
        ])->assertSessionHasErrors('order');

        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame('draft', $order->rounds()->firstOrFail()->status);
    }

    public function test_salesperson_can_switch_with_pin_and_new_order_keeps_that_owner(): void
    {
        $waiter = User::factory()->create(['name' => 'Kamerieri Test']);
        DB::table('tenant_user')->where('user_id', $waiter->id)->update([
            'pos_salesperson_enabled' => true,
            'pos_pin_hash' => Hash::make('1234'),
        ]);

        $this->actingAs($this->admin)->post(route('pos.salesperson.switch'), [
            'user_id' => $waiter->id,
            'pin' => '1234',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('pos.tables'));
        $table = PosTable::firstOrFail();
        $this->actingAs($this->admin)->post(route('pos.tables.rounds.store', $table), [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
            'send' => true,
        ])->assertSessionHasNoErrors();

        $order = PosOrder::firstOrFail();
        $this->assertSame($waiter->id, $order->salesperson_id);
        $this->assertSame($this->admin->id, $order->created_by);
    }

    public function test_wrong_pin_does_not_change_the_active_salesperson(): void
    {
        $waiter = User::factory()->create();
        DB::table('tenant_user')->where('user_id', $waiter->id)->update([
            'pos_salesperson_enabled' => true,
            'pos_pin_hash' => Hash::make('1234'),
        ]);

        $this->actingAs($this->admin)->post(route('pos.salesperson.switch'), [
            'user_id' => $waiter->id,
            'pin' => '9999',
        ])->assertSessionHasErrors('pin');

        $this->actingAs($this->admin)->get(route('pos.tables'));
        $table = PosTable::firstOrFail();
        $this->actingAs($this->admin)->post(route('pos.tables.rounds.store', $table), [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
            'send' => true,
        ]);

        $this->assertSame($this->admin->id, PosOrder::firstOrFail()->salesperson_id);
    }

    public function test_open_order_salesperson_can_be_transferred_with_target_pin(): void
    {
        $waiter = User::factory()->create(['name' => 'Kamerieri i Dytë']);
        DB::table('tenant_user')->where('user_id', $waiter->id)->update([
            'pos_salesperson_enabled' => true,
            'pos_pin_hash' => Hash::make('2468'),
        ]);
        $this->actingAs($this->admin)->get(route('pos.tables'));
        $table = PosTable::firstOrFail();
        $this->actingAs($this->admin)->post(route('pos.tables.rounds.store', $table), [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
            'send' => true,
        ]);
        $order = PosOrder::firstOrFail();

        $this->actingAs($this->admin)->post(route('pos.salesperson.transfer', $order), [
            'user_id' => $waiter->id,
            'pin' => '2468',
        ])->assertSessionHasNoErrors();

        $this->assertSame($waiter->id, $order->fresh()->salesperson_id);
    }

    public function test_admin_can_create_a_dedicated_waiter_account_with_unique_pos_pin(): void
    {
        $this->actingAs($this->admin)->post(route('settings.pos.salespeople.store'), [
            'name' => 'Arta Kamarierja',
            'email' => 'arta.waiter@example.com',
            'password' => 'WaiterPass1!',
            'pin' => '2580',
        ])->assertSessionHasNoErrors();

        $waiter = User::withoutGlobalScopes()->where('email', 'arta.waiter@example.com')->firstOrFail();
        $membership = DB::table('tenant_user')->where('user_id', $waiter->id)->firstOrFail();

        $this->assertTrue($waiter->hasRole('pos_staff'));
        $this->assertTrue((bool) $membership->pos_salesperson_enabled);
        $this->assertTrue(Hash::check('2580', $membership->pos_pin_hash));
    }

    public function test_waiter_creation_rejects_a_pin_used_by_another_waiter(): void
    {
        $existing = User::factory()->create();
        DB::table('tenant_user')->where('user_id', $existing->id)->update([
            'pos_salesperson_enabled' => true,
            'pos_pin_hash' => Hash::make('2580'),
        ]);

        $this->actingAs($this->admin)->post(route('settings.pos.salespeople.store'), [
            'name' => 'Kamarieri i Ri',
            'email' => 'second.waiter@example.com',
            'password' => 'WaiterPass2!',
            'pin' => '2580',
        ])->assertSessionHasErrors('pin');

        $this->assertDatabaseMissing('users', ['email' => 'second.waiter@example.com']);
    }

    public function test_waiter_creation_reserves_pin_held_by_an_inactive_membership(): void
    {
        $inactive = User::factory()->create();
        DB::table('tenant_user')->where('user_id', $inactive->id)->update([
            'is_active' => false,
            'pos_salesperson_enabled' => true,
            'pos_pin_hash' => Hash::make('2580'),
        ]);

        $this->actingAs($this->admin)->post(route('settings.pos.salespeople.store'), [
            'name' => 'Kamarieri i Ri',
            'email' => 'inactive-pin.waiter@example.com',
            'password' => 'WaiterPass2!',
            'pin' => '2580',
        ])->assertSessionHasErrors('pin');

        $this->assertDatabaseMissing('users', ['email' => 'inactive-pin.waiter@example.com']);
    }

    public function test_pos_settings_reject_assigning_an_existing_pin_to_another_waiter(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        DB::table('tenant_user')->where('user_id', $first->id)->update([
            'pos_salesperson_enabled' => true,
            'pos_pin_hash' => Hash::make('2580'),
        ]);

        $this->actingAs($this->admin)->put(route('settings.pos'), [
            'service_mode' => 'hybrid',
            'opening_view' => 'tables',
            'salesperson_enabled' => true,
            'salesperson_required' => true,
            'staff' => [[
                'id' => $second->id,
                'enabled' => true,
                'pin' => '2580',
                'clear_pin' => false,
            ]],
        ])->assertSessionHasErrors('staff.0.pin');

        $membership = DB::table('tenant_user')->where('user_id', $second->id)->firstOrFail();
        $this->assertNull($membership->pos_pin_hash);
    }
}
