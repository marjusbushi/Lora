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
use Tests\TestCase;

/**
 * The bridge between the POS menu and the inventory category tree: each tree
 * node gets at most ONE auto-created menu group; the tree owns names and
 * existence, the menu group keeps the POS-only settings (outlet, warehouse).
 */
class PosMenuBridgeTest extends TestCase
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

    public function test_a_tree_node_gets_exactly_one_menu_group_created_on_demand(): void
    {
        $this->admin();
        $this->restoreTenant();
        $pije = InventoryCategory::create(['name' => 'Pije']);
        $vere = InventoryCategory::create(['name' => 'Verë', 'parent_id' => $pije->id]);

        $group = MenuCategory::forInventoryCategory($vere);
        $again = MenuCategory::forInventoryCategory($vere);

        $this->assertSame($group->id, $again->id);
        $this->assertSame('Verë', $group->name);
        $this->assertSame($vere->id, $group->inventory_category_id);
        $this->assertSame(1, MenuCategory::query()->where('inventory_category_id', $vere->id)->count());
    }

    public function test_renaming_a_tree_node_renames_its_linked_menu_group(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        $pije = InventoryCategory::create(['name' => 'Pije']);
        $group = MenuCategory::forInventoryCategory($pije);

        $this->actingAs($admin)->put(route('inventory.categories.update', $pije), ['name' => 'Pije & Freskuese'])
            ->assertSessionHasNoErrors();

        $this->restoreTenant();
        $this->assertSame('Pije & Freskuese', $group->fresh()->name);
    }

    public function test_a_node_with_a_populated_menu_group_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        $pije = InventoryCategory::create(['name' => 'Pije']);
        $group = MenuCategory::forInventoryCategory($pije);
        MenuItem::create(['menu_category_id' => $group->id, 'name' => 'Ujë', 'price' => 1, 'is_available' => true]);

        $this->actingAs($admin)->delete(route('inventory.categories.destroy', $pije))->assertSessionHas('error');
        $this->restoreTenant();
        $this->assertNotNull(InventoryCategory::query()->find($pije->id));
    }

    public function test_deleting_an_empty_node_removes_its_empty_menu_group_too(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        $pije = InventoryCategory::create(['name' => 'Pije']);
        $group = MenuCategory::forInventoryCategory($pije);

        $this->actingAs($admin)->delete(route('inventory.categories.destroy', $pije))->assertSessionHas('success');

        $this->restoreTenant();
        $this->assertNull(InventoryCategory::query()->find($pije->id));
        $this->assertNull(MenuCategory::query()->find($group->id));
    }

    public function test_manual_menu_categories_without_a_link_keep_working(): void
    {
        $this->admin();
        $this->restoreTenant();
        // Pre-unification groups (or edge rows) with no tree link are untouched
        // by the bridge — the POS keeps rendering them.
        $legacy = MenuCategory::create(['name' => 'Speciale', 'outlet' => 'bar']);
        MenuItem::create(['menu_category_id' => $legacy->id, 'name' => 'Kokteil', 'price' => 8, 'is_available' => true]);

        $this->assertNull($legacy->inventory_category_id);
        $this->assertSame(1, $legacy->items()->count());
    }
}
