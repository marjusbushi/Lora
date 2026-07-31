<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannexImportStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
        ]);
    }

    private function fakeChannexStructure(): void
    {
        Http::fake([
            'https://staging.channex.io/api/v1/room_types*' => Http::response(['data' => [
                ['id' => 'RT-1', 'attributes' => ['title' => 'Deluxe Apartment', 'count_of_rooms' => 2, 'occ_adults' => 4]],
                ['id' => 'RT-2', 'attributes' => ['title' => 'Studio', 'count_of_rooms' => 1, 'occ_adults' => 2]],
            ]], 200),
        ]);
    }

    public function test_imports_room_types_and_placeholder_rooms_from_channex(): void
    {
        $this->fakeChannexStructure();

        $this->artisan('channex:import-property-structure')->assertExitCode(0);

        $deluxe = RoomType::query()->whereRaw("LOWER(name) = 'deluxe apartment'")->sole();
        $this->assertSame(4, (int) $deluxe->max_occupancy);
        $this->assertSame(0.0, (float) $deluxe->base_price); // prices stay the owner's job
        $this->assertSame(2, Room::query()->where('room_type_id', $deluxe->id)->count());

        $this->assertSame(2, RoomType::query()->count());
        $this->assertSame(3, Room::query()->count());
        $this->assertSame(['101', '102', '103'], Room::query()->orderBy('room_number')->pluck('room_number')->all());
    }

    public function test_rerun_is_idempotent_and_never_overwrites_prices(): void
    {
        $this->fakeChannexStructure();

        $this->artisan('channex:import-property-structure')->assertExitCode(0);

        // The owner sets a real price and renames a door — a re-run must not undo it.
        RoomType::query()->whereRaw("LOWER(name) = 'studio'")->sole()->update(['base_price' => 75]);

        $this->artisan('channex:import-property-structure')->assertExitCode(0);

        $this->assertSame(2, RoomType::query()->count());
        $this->assertSame(3, Room::query()->count());
        $this->assertSame(75.0, (float) RoomType::query()->whereRaw("LOWER(name) = 'studio'")->sole()->base_price);
    }

    public function test_refuses_without_tenant_outside_testing(): void
    {
        // No Channex call may happen — the tenant gate fires first.
        $env = $this->app['env'];
        try {
            $this->app['env'] = 'production';
            $this->artisan('channex:import-property-structure')->assertExitCode(1);
        } finally {
            $this->app['env'] = $env;
        }

        $this->assertSame(0, RoomType::query()->count());
    }

    public function test_fails_loud_when_the_channex_property_is_empty(): void
    {
        Http::fake([
            'https://staging.channex.io/api/v1/room_types*' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('channex:import-property-structure')->assertExitCode(1);
        $this->assertSame(0, RoomType::query()->count());
    }
}
