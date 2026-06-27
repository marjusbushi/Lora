<?php

namespace Tests\Feature;

use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomTypeAmenitiesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** The HTTP path that produced the prod 500 (Request::validated on base Request). */
    public function test_admin_can_create_room_type_with_amenities(): void
    {
        $response = $this->actingAs($this->admin())->post(route('settings.room-types.store'), [
            'name' => 'Deluxe Test',
            'description' => 'Test',
            'base_price' => 80,
            'max_occupancy' => 3,
            'amenities' => ['WiFi', 'TV', 'AC'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertEquals(['WiFi', 'TV', 'AC'], RoomType::where('name', 'Deluxe Test')->first()->amenities);
    }

    public function test_admin_can_update_room_type_amenities(): void
    {
        $type = RoomType::create([
            'name' => 'Std Test', 'base_price' => 50, 'max_occupancy' => 2, 'amenities' => ['WiFi'],
        ]);

        $response = $this->actingAs($this->admin())->put(route('settings.room-types.update', $type->id), [
            'name' => 'Std Test',
            'base_price' => 55,
            'max_occupancy' => 2,
            'amenities' => ['WiFi', 'Minibar'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertEquals(['WiFi', 'Minibar'], $type->fresh()->amenities);
    }
}
