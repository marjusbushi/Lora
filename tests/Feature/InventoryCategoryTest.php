<?php

namespace Tests\Feature;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Managed inventory categories: roots with up to two levels of subcategories
 * (Pije → Alkoolike → Verë), unlimited siblings, optional on the article.
 * The free-text field is gone — one mechanism only.
 */
class InventoryCategoryTest extends TestCase
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

    /** @return array{0: InventoryCategory, 1: InventoryCategory, 2: InventoryCategory} Pije → Alkoolike → Verë */
    private function tree(): array
    {
        $pije = InventoryCategory::create(['name' => 'Pije']);
        $alkoolike = InventoryCategory::create(['name' => 'Alkoolike', 'parent_id' => $pije->id]);
        $vere = InventoryCategory::create(['name' => 'Verë', 'parent_id' => $alkoolike->id]);

        return [$pije, $alkoolike, $vere];
    }

    public function test_two_levels_below_a_root_are_allowed_and_a_third_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('inventory.categories.store'), ['name' => 'Pije'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->restoreTenant();
        $pije = InventoryCategory::query()->sole();

        $this->actingAs($admin)->post(route('inventory.categories.store'), ['name' => 'Alkoolike', 'parent_id' => $pije->id])
            ->assertSessionHasNoErrors();
        $this->restoreTenant();
        $alkoolike = InventoryCategory::query()->where('name', 'Alkoolike')->sole();

        $this->actingAs($admin)->post(route('inventory.categories.store'), ['name' => 'Verë', 'parent_id' => $alkoolike->id])
            ->assertSessionHasNoErrors();
        $this->restoreTenant();
        $vere = InventoryCategory::query()->where('name', 'Verë')->sole();
        $this->assertSame(2, $vere->depth());

        // A sub-subcategory cannot parent anything — the tree caps at two levels below a root.
        $this->actingAs($admin)->post(route('inventory.categories.store'), ['name' => 'E kuqe', 'parent_id' => $vere->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_duplicate_names_are_blocked_per_level_not_globally(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        [, $alkoolike] = $this->tree();

        $this->actingAs($admin)->post(route('inventory.categories.store'), ['name' => 'Pije'])
            ->assertSessionHasErrors('name');

        // The same word is fine on another level.
        $this->actingAs($admin)->post(route('inventory.categories.store'), ['name' => 'Pije', 'parent_id' => $alkoolike->id])
            ->assertSessionHasNoErrors();
    }

    public function test_items_attach_to_any_level_and_the_filter_includes_descendants(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        [$pije, , $vere] = $this->tree();
        $tjeter = InventoryCategory::create(['name' => 'Tjetër']);

        InventoryItem::create(['name' => 'Merlot', 'sku' => 'MER', 'type' => 'product', 'unit' => 'piece', 'category_id' => $vere->id, 'is_active' => true]);
        InventoryItem::create(['name' => 'Ujë', 'sku' => 'UJE', 'type' => 'product', 'unit' => 'piece', 'category_id' => $pije->id, 'is_active' => true]);
        InventoryItem::create(['name' => 'Peshqir', 'sku' => 'PSH', 'type' => 'consumable', 'unit' => 'piece', 'category_id' => $tjeter->id, 'is_active' => true]);

        // Filtering by the ROOT catches the item on the sub-subcategory too.
        $this->actingAs($admin)->get(route('inventory.items', ['category_id' => $pije->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Items')
                ->has('items', 2)
                ->where('items.0.category', 'Verë'));
    }

    public function test_global_search_finds_articles_by_category_name(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        [, , $vere] = $this->tree();
        InventoryItem::create(['name' => 'Merlot', 'sku' => 'MER', 'type' => 'product', 'unit' => 'piece', 'category_id' => $vere->id, 'is_active' => true]);

        $response = $this->actingAs($admin)->get(route('global-search', ['q' => 'Verë']))->assertOk();
        $flat = collect($response->json())->flatten();
        $this->assertTrue($flat->contains('Merlot'), 'Global search should surface the article via its category name.');
    }

    public function test_only_empty_categories_can_be_deleted(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        [$pije, $alkoolike, $vere] = $this->tree();
        InventoryItem::create(['name' => 'Merlot', 'sku' => 'MER', 'type' => 'product', 'unit' => 'piece', 'category_id' => $vere->id, 'is_active' => true]);

        // Has a child → refused.
        $this->actingAs($admin)->delete(route('inventory.categories.destroy', $pije))->assertSessionHas('error');
        // Has an item → refused.
        $this->actingAs($admin)->delete(route('inventory.categories.destroy', $vere))->assertSessionHas('error');
        $this->restoreTenant();
        $this->assertSame(3, InventoryCategory::query()->count());

        // Empty leaf → gone (after detaching the item).
        InventoryItem::query()->update(['category_id' => null]);
        $this->actingAs($admin)->delete(route('inventory.categories.destroy', $vere))->assertSessionHas('success');
        $this->restoreTenant();
        $this->assertNull(InventoryCategory::query()->find($vere->id));
        $this->assertSame(2, InventoryCategory::query()->count());
    }

    public function test_rename_keeps_level_uniqueness(): void
    {
        $admin = $this->admin();
        $this->restoreTenant();
        [$pije, $alkoolike] = $this->tree();
        InventoryCategory::create(['name' => 'Joalkoolike', 'parent_id' => $pije->id]);

        $this->actingAs($admin)->put(route('inventory.categories.update', $alkoolike), ['name' => 'Joalkoolike'])
            ->assertSessionHasErrors('name');
        $this->actingAs($admin)->put(route('inventory.categories.update', $alkoolike), ['name' => 'Me alkool'])
            ->assertSessionHasNoErrors();
        $this->restoreTenant();
        $this->assertSame('Me alkool', $alkoolike->fresh()->name);
    }
}
