<?php

namespace Tests\Feature;

use App\Models\FolioItem;
use App\Models\Guest;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationFolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_folio_enforces_hotel_charge_rules(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create([
            'name' => 'Standard',
            'base_price' => 80,
            'max_occupancy' => 2,
            'amenities' => [],
        ]);
        $room = Room::create([
            'room_type_id' => $type->id,
            'room_number' => '701',
            'floor' => 7,
            'status' => 'available',
        ]);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);
        $reservation = Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => $admin->id,
            'check_in_date' => '2026-09-10',
            'check_out_date' => '2026-09-12',
            'status' => 'confirmed',
            'total_amount' => 160,
            'adults' => 2,
        ]);

        $this->actingAs($admin)->post(route('reservations.folio.add', $reservation), [
            'type' => 'extra',
            'description' => 'Lavanderi',
            'amount' => 12,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('reservations.folio.add', $reservation), [
            'type' => 'bar',
            'description' => 'Pije e futur me dore',
            'amount' => 5,
        ])->assertSessionHasErrors('type');

        $this->actingAs($admin)->post(route('reservations.folio.add', $reservation), [
            'type' => 'minibar',
            'description' => 'Ujë i futur me dorë',
            'amount' => 2,
        ])->assertSessionHasErrors('type');

        $this->actingAs($admin)->post(route('reservations.folio.add', $reservation), [
            'type' => 'discount',
            'description' => 'Zbritje mbi totalin',
            'amount' => 1000,
        ])->assertSessionHasErrors('amount');

        $reservation->update(['status' => 'checked_out']);
        $this->actingAs($admin)->post(route('reservations.folio.add', $reservation), [
            'type' => 'extra',
            'description' => 'Tarife pas mbylljes',
            'amount' => 10,
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseCount('folio_items', 1);
        $this->assertDatabaseHas('folio_items', [
            'reservation_id' => $reservation->id,
            'type' => 'extra',
            'description' => 'Lavanderi',
        ]);
    }

    public function test_minibar_posts_folio_and_stock_atomically_and_idempotently(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = RoomType::create([
            'name' => 'Standard', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => [],
        ]);
        $room = Room::create([
            'room_type_id' => $type->id, 'room_number' => '702', 'floor' => 7, 'status' => 'occupied',
        ]);
        $guest = Guest::create(['first_name' => 'Mini', 'last_name' => 'Bar']);
        $reservation = Reservation::create([
            'room_id' => $room->id, 'guest_id' => $guest->id, 'created_by' => $admin->id,
            'check_in_date' => '2026-09-10', 'check_out_date' => '2026-09-12',
            'status' => 'checked_in', 'total_amount' => 160, 'adults' => 2,
        ]);
        $warehouse = Warehouse::create([
            'name' => 'Magazina e dhomave', 'type' => 'rooms', 'is_active' => true,
        ]);
        $item = InventoryItem::create([
            'name' => 'Ujë 0.5L', 'sku' => 'MIN-UJE', 'type' => 'product', 'unit' => 'piece',
            'average_cost' => 0.35, 'selling_price' => 2.50,
            'sell_in_rooms' => true, 'room_selling_price' => 2.50,
            'room_warehouse_id' => $warehouse->id, 'is_active' => true,
        ]);
        app(InventoryLedger::class)->openingBalance($item, $warehouse, 5, 0.35, null, $admin->id);
        $reference = '24e18df6-2409-4f37-a823-0d53540a0b7a';

        $payload = [
            'inventory_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'inventory_reference' => $reference,
        ];
        $this->actingAs($admin)->post(route('reservations.folio.inventory', $reservation), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $folioItem = FolioItem::where('inventory_reference', $reference)->firstOrFail();
        $this->assertSame(5.0, (float) $folioItem->amount);
        $this->assertSame(3.0, $item->fresh()->stock($warehouse->id));
        $this->assertDatabaseHas('inventory_movements', [
            'sourceable_type' => FolioItem::class,
            'sourceable_id' => $folioItem->id,
            'inventory_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'room_charge',
            'quantity' => -2,
        ]);

        $this->actingAs($admin)->post(route('reservations.folio.inventory', $reservation), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, FolioItem::where('inventory_reference', $reference)->count());
        $this->assertSame(1, InventoryMovement::where('sourceable_type', FolioItem::class)
            ->where('sourceable_id', $folioItem->id)->where('type', 'room_charge')->count());
        $this->assertSame(3.0, $item->fresh()->stock($warehouse->id));

        $this->actingAs($admin)->post(route('reservations.folio.inventory', $reservation), [
            ...$payload,
            'quantity' => 4,
            'inventory_reference' => '7336be73-7f0a-45d2-a643-322d099c7790',
        ])->assertSessionHasErrors('quantity');
        $this->assertSame(1, FolioItem::where('type', 'minibar')->count());
        $this->assertSame(3.0, $item->fresh()->stock($warehouse->id));
    }
}
