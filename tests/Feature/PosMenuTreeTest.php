<?php

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The sale screen's drill-down skeleton: only tree nodes whose linked menu
 * group offers something (plus their ancestors) are shipped, flat and
 * depth-annotated, so the cashier can walk Niveli 1 → 2 → 3 → Artikujt.
 */
class PosMenuTreeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function restoreTenant(): void
    {
        app(TenantContext::class)->set(Tenant::query()->sole());
    }

    public function test_menu_tree_ships_offering_nodes_with_their_ancestors_only(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        $pije = InventoryCategory::create(['name' => 'Pije']);
        $alkoolike = InventoryCategory::create(['name' => 'Alkoolike', 'parent_id' => $pije->id]);
        $vere = InventoryCategory::create(['name' => 'Verë', 'parent_id' => $alkoolike->id]);
        // A node with no POS offering must be pruned from the drill-down.
        InventoryCategory::create(['name' => 'Pastrimi']);

        $group = MenuCategory::forInventoryCategory($vere);
        MenuItem::create(['menu_category_id' => $group->id, 'name' => 'Merlot', 'price' => 6, 'is_available' => true]);

        $this->actingAs($admin)->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->has('menuTree', 3)
                ->where('menuTree.0.name', 'Pije')
                ->where('menuTree.0.depth', 0)
                ->where('menuTree.1.name', 'Alkoolike')
                ->where('menuTree.1.depth', 1)
                ->where('menuTree.2.name', 'Verë')
                ->where('menuTree.2.depth', 2)
                ->where('menu.0.inventory_category_id', $vere->id));
    }

    public function test_legacy_groups_without_a_tree_link_ship_but_stay_out_of_the_tree(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        $legacy = MenuCategory::create(['name' => 'Speciale', 'outlet' => 'bar']);
        MenuItem::create(['menu_category_id' => $legacy->id, 'name' => 'Kokteil', 'price' => 8, 'is_available' => true]);

        $this->actingAs($admin)->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->has('menuTree', 0)
                ->where('menu.0.name', 'Speciale')
                ->where('menu.0.inventory_category_id', null));
    }

    public function test_empty_linked_groups_do_not_produce_dead_drilldown_paths(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        $pije = InventoryCategory::create(['name' => 'Pije']);
        MenuCategory::forInventoryCategory($pije);

        $this->actingAs($admin)->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->has('menuTree', 0));
    }
}
