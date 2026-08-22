<?php

namespace Tests\Feature;

use App\Models\RoomType;
use App\Models\User;
use App\Services\ChannexClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoomTypeMaxChildrenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_room_type_stores_and_updates_max_children(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.room-types.store'), [
            'name' => 'Familjare', 'base_price' => 90, 'max_occupancy' => 4, 'max_children' => 2, 'amenities' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $type = RoomType::where('name', 'Familjare')->first();
        $this->assertSame(2, (int) $type->max_children);

        $this->actingAs($admin)->put(route('settings.room-types.update', $type->id), [
            'name' => 'Familjare', 'base_price' => 90, 'max_occupancy' => 4, 'max_children' => 0, 'amenities' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $type->fresh()->max_children);
    }

    public function test_max_children_above_occupancy_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.room-types.store'), [
            'name' => 'E vogël', 'base_price' => 60, 'max_occupancy' => 2, 'max_children' => 3, 'amenities' => [],
        ])->assertSessionHasErrors('max_children');

        $this->assertNull(RoomType::where('name', 'E vogël')->first());
    }

    /** Codex #592 P2: ulja e kapacitetit PA fushën e re s'lë dot max_children mbi kapacitetin. */
    public function test_capacity_drop_without_the_field_clamps_the_stored_child_limit(): void
    {
        $admin = $this->admin();
        $type = RoomType::create(['name' => 'Familjare', 'base_price' => 90, 'max_occupancy' => 4, 'max_children' => 3, 'amenities' => []]);

        $this->actingAs($admin)->put(route('settings.room-types.update', $type->id), [
            'name' => 'Familjare', 'base_price' => 90, 'max_occupancy' => 2, 'amenities' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $type->fresh()->max_children);
    }

    public function test_missing_max_children_falls_to_the_db_default(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.room-types.store'), [
            'name' => 'Pa fushë', 'base_price' => 70, 'max_occupancy' => 2, 'amenities' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, (int) RoomType::where('name', 'Pa fushë')->first()->max_children);
    }

    /**
     * Backfill-i REAL i migrimit mbi rreshta ekzistues: down() e heq kolonën,
     * up() e rishton dhe llogarit max_occupancy - 1 (kurrë nën 0).
     */
    public function test_migration_backfills_max_children_from_occupancy(): void
    {
        RoomType::create(['name' => 'Teke', 'base_price' => 50, 'max_occupancy' => 1, 'amenities' => []]);
        RoomType::create(['name' => 'Dyshe', 'base_price' => 70, 'max_occupancy' => 2, 'amenities' => []]);
        RoomType::create(['name' => 'Familjare', 'base_price' => 95, 'max_occupancy' => 4, 'amenities' => []]);

        $migration = require base_path('database/migrations/2026_08_22_170000_add_max_children_to_room_types_table.php');
        $migration->down();
        $migration->up();

        $byName = RoomType::query()->pluck('max_children', 'name');
        $this->assertSame(0, (int) $byName['Teke']);
        $this->assertSame(1, (int) $byName['Dyshe']);
        $this->assertSame(3, (int) $byName['Familjare']);
    }

    public function test_channex_room_type_carries_the_real_children_occupancy(): void
    {
        Http::fake(['*room_types*' => Http::response(['data' => ['id' => 'RT-77']], 201)]);

        $id = (new ChannexClient)->createRoomType('Familjare', 2, 4, 3);

        $this->assertSame('RT-77', $id);
        Http::assertSent(function ($r) {
            $rt = $r->data()['room_type'] ?? [];

            return (int) ($rt['occ_adults'] ?? 0) === 4
                && (int) ($rt['occ_children'] ?? -1) === 3
                && (int) ($rt['default_occupancy'] ?? 0) === 4;
        });
    }
}
