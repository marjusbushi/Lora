<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomTypeManageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_settings_page_loads_with_amenities_present(): void
    {
        Amenity::create(['name' => 'WiFi', 'sort_order' => 1]);
        RoomType::create(['name' => 'Std', 'base_price' => 50, 'max_occupancy' => 2, 'amenities' => ['WiFi']]);

        $this->actingAs($this->admin())->get(route('settings.index'))->assertOk();
    }

    public function test_admin_can_edit_a_room_type_and_page_reloads_ok(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Std', 'base_price' => 50, 'max_occupancy' => 2, 'amenities' => ['WiFi']]);

        $this->actingAs($admin)
            ->put(route('settings.room-types.update', $type->id), [
                'name' => 'Standard', 'base_price' => 60, 'max_occupancy' => 3, 'amenities' => ['WiFi', 'TV'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertEquals('Standard', $type->fresh()->name);

        // The redirect target (settings index) must render without error.
        $this->actingAs($admin)->get(route('settings.index'))->assertOk();
    }

    public function test_room_type_without_rooms_deletes_but_with_rooms_is_blocked(): void
    {
        $admin = $this->admin();

        $empty = RoomType::create(['name' => 'Empty', 'base_price' => 40, 'max_occupancy' => 2, 'amenities' => []]);
        $this->actingAs($admin)
            ->delete(route('settings.room-types.destroy', $empty->id))
            ->assertRedirect();
        $this->assertNull(RoomType::find($empty->id));

        $used = RoomType::create(['name' => 'Used', 'base_price' => 40, 'max_occupancy' => 2, 'amenities' => []]);
        Room::create(['room_type_id' => $used->id, 'room_number' => '101', 'floor' => 1, 'status' => 'available']);
        $this->actingAs($admin)
            ->delete(route('settings.room-types.destroy', $used->id))
            ->assertSessionHas('error');
        $this->assertNotNull(RoomType::find($used->id));
    }
}
