<?php

namespace Tests\Feature;

use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BreakfastIncludedTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_room_type_stores_and_toggles_breakfast_included(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.room-types.store'), [
            'name' => 'Deluxe', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => [], 'breakfast_included' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $type = RoomType::where('name', 'Deluxe')->first();
        $this->assertTrue($type->breakfast_included);

        $this->actingAs($admin)->put(route('settings.room-types.update', $type->id), [
            'name' => 'Deluxe', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => [], 'breakfast_included' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($type->fresh()->breakfast_included);
    }

    public function test_public_home_renders_with_breakfast_data(): void
    {
        RoomType::create(['name' => 'B', 'base_price' => 70, 'max_occupancy' => 2, 'amenities' => [], 'breakfast_included' => true]);

        $this->get('/')->assertOk();
    }
}
