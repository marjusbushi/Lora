<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryLedger;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Controlled stock exits: finance/admin write off damaged, lost or expired
 * goods as audited ledger movements — never silent quantity edits. Everyone
 * else is denied, and service items (no stock) are rejected outright.
 */
class InventoryWriteOffTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** HTTP requests clear the tenant context; restore it before stock() reads. */
    private function restoreTenant(): void
    {
        app(TenantContext::class)->set(Tenant::query()->sole());
    }

    /** @return array{0: InventoryItem, 1: Warehouse} */
    private function stockedItem(float $quantity = 10): array
    {
        $warehouse = Warehouse::ensureDefault();
        $item = InventoryItem::create([
            'name' => 'Batanije', 'sku' => 'BLK-1', 'type' => 'consumable',
            'unit' => 'piece', 'average_cost' => 12.5, 'is_active' => true,
        ]);
        app(InventoryLedger::class)->openingBalance($item, $warehouse, $quantity, 12.5, null, null);

        return [$item, $warehouse];
    }

    public function test_admin_writes_off_damaged_stock_with_a_full_audit_trail(): void
    {
        $admin = $this->userWithRole('admin');
        [$item, $warehouse] = $this->stockedItem(10);

        $this->actingAs($admin)->post(route('inventory.write-offs.store'), [
            'inventory_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'reason' => 'damaged',
            'notes' => '2 batanije të grisura',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->restoreTenant();
        $movement = InventoryMovement::query()->where('type', 'write_off')->sole();
        $this->assertSame(-2.0, (float) $movement->quantity);
        $this->assertSame(12.5, (float) $movement->unit_cost);
        $this->assertSame($admin->id, $movement->created_by);
        $this->assertSame('Dëmtuar · 2 batanije të grisura', $movement->notes);
        $this->assertSame(8.0, $item->fresh()->stock($warehouse->id));
    }

    public function test_write_off_beyond_available_stock_is_rejected(): void
    {
        $admin = $this->userWithRole('admin');
        [$item, $warehouse] = $this->stockedItem(10);

        $this->actingAs($admin)->post(route('inventory.write-offs.store'), [
            'inventory_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 11,
            'reason' => 'damaged',
        ])->assertSessionHasErrors('quantity');

        $this->restoreTenant();
        $this->assertSame(10.0, $item->fresh()->stock($warehouse->id));
        $this->assertSame(0, InventoryMovement::query()->where('type', 'write_off')->count());
    }

    public function test_receptionist_cannot_write_off(): void
    {
        $receptionist = $this->userWithRole('receptionist');
        [$item, $warehouse] = $this->stockedItem(10);

        $this->actingAs($receptionist)->post(route('inventory.write-offs.store'), [
            'inventory_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'reason' => 'lost',
        ])->assertForbidden();
    }

    public function test_finance_role_sees_inventory_and_writes_off(): void
    {
        $finance = $this->userWithRole('finance');
        [$item, $warehouse] = $this->stockedItem(10);

        $this->actingAs($finance)->get(route('inventory.items'))->assertOk();

        $this->actingAs($finance)->post(route('inventory.write-offs.store'), [
            'inventory_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'reason' => 'lost',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->restoreTenant();
        $this->assertSame(9.0, $item->fresh()->stock($warehouse->id));
        $this->assertSame('Humbur', InventoryMovement::query()->where('type', 'write_off')->sole()->notes);
    }

    public function test_service_items_cannot_be_written_off(): void
    {
        $admin = $this->userWithRole('admin');
        $warehouse = Warehouse::ensureDefault();
        $service = InventoryItem::create([
            'name' => 'Lavanderi', 'sku' => 'SRV-1', 'type' => 'service',
            'unit' => 'piece', 'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('inventory.write-offs.store'), [
            'inventory_item_id' => $service->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'reason' => 'other',
        ])->assertSessionHasErrors('inventory_item_id');
    }
}
