<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderRound;
use App\Models\PosShift;
use App\Models\PosTable;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
