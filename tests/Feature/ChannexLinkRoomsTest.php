<?php

namespace Tests\Feature;

use App\Models\ChannelMapping;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannexLinkRoomsTest extends TestCase
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

    public function test_links_pms_room_types_to_channex_by_title(): void
    {
        $studio = RoomType::create(['name' => 'Studio', 'base_price' => 120, 'max_occupancy' => 3]);
        $apartment = RoomType::create(['name' => 'Apartment', 'base_price' => 150, 'max_occupancy' => 6]);

        Http::fake([
            '*room_types*' => Http::response(['data' => [
                ['id' => 'RT-S', 'attributes' => ['title' => 'Studio']],
                ['id' => 'RT-A', 'attributes' => ['title' => 'Apartment']],
            ]]),
            '*rate_plans*' => Http::response(['data' => [
                // Channex is JSON:API: room type lives under relationships.
                ['id' => 'RP-S', 'relationships' => ['room_type' => ['data' => ['id' => 'RT-S', 'type' => 'room_type']]]],
                // no rate plan for Apartment -> mapping should still link, plan null
            ]]),
        ]);

        $this->artisan('channex:link-rooms')->assertSuccessful();

        $this->assertDatabaseHas('channel_mappings', [
            'channel' => 'channex',
            'room_type_id' => $studio->id,
            'channex_room_type_id' => 'RT-S',
            'channex_rate_plan_id' => 'RP-S',
            'channex_property_id' => 'PROP-1',
        ]);
        $this->assertDatabaseHas('channel_mappings', [
            'channel' => 'channex',
            'room_type_id' => $apartment->id,
            'channex_room_type_id' => 'RT-A',
            'channex_rate_plan_id' => null,
        ]);
    }

    public function test_link_is_idempotent_on_rerun(): void
    {
        $studio = RoomType::create(['name' => 'Studio', 'base_price' => 120, 'max_occupancy' => 3]);

        Http::fake([
            '*room_types*' => Http::response(['data' => [['id' => 'RT-S', 'attributes' => ['title' => 'Studio']]]]),
            '*rate_plans*' => Http::response(['data' => [['id' => 'RP-S', 'relationships' => ['room_type' => ['data' => ['id' => 'RT-S']]]]]]),
        ]);

        $this->artisan('channex:link-rooms')->assertSuccessful();
        $this->artisan('channex:link-rooms')->assertSuccessful();

        $this->assertSame(1, ChannelMapping::where('channel', 'channex')->where('room_type_id', $studio->id)->count());
    }

    public function test_not_configured_returns_failure(): void
    {
        config(['services.channex.api_key' => '']);
        RoomType::create(['name' => 'Studio', 'base_price' => 120, 'max_occupancy' => 3]);

        $this->artisan('channex:link-rooms')->assertFailed();
        $this->assertDatabaseCount('channel_mappings', 0);
    }

    public function test_empty_channex_account_writes_nothing(): void
    {
        RoomType::create(['name' => 'Studio', 'base_price' => 120, 'max_occupancy' => 3]);
        Http::fake([
            '*room_types*' => Http::response(['data' => []]),
            '*rate_plans*' => Http::response(['data' => []]),
        ]);

        $this->artisan('channex:link-rooms')->assertSuccessful();
        $this->assertDatabaseCount('channel_mappings', 0);
    }

    public function test_unmatched_room_type_is_not_linked_and_warns(): void
    {
        $orphan = RoomType::create(['name' => 'Penthouse', 'base_price' => 300, 'max_occupancy' => 4]);
        Http::fake([
            '*room_types*' => Http::response(['data' => [['id' => 'RT-S', 'attributes' => ['title' => 'Studio']]]]),
            '*rate_plans*' => Http::response(['data' => []]),
        ]);

        $this->artisan('channex:link-rooms')
            ->expectsOutputToContain('Unmatched')
            ->assertSuccessful();
        $this->assertDatabaseMissing('channel_mappings', ['room_type_id' => $orphan->id]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        RoomType::create(['name' => 'Studio', 'base_price' => 120, 'max_occupancy' => 3]);
        Http::fake([
            '*room_types*' => Http::response(['data' => [['id' => 'RT-S', 'attributes' => ['title' => 'Studio']]]]),
            '*rate_plans*' => Http::response(['data' => [['id' => 'RP-S', 'relationships' => ['room_type' => ['data' => ['id' => 'RT-S']]]]]]),
        ]);

        $this->artisan('channex:link-rooms', ['--dry' => true])->assertSuccessful();
        $this->assertDatabaseCount('channel_mappings', 0);
    }

    public function test_matches_titles_case_insensitively(): void
    {
        $rt = RoomType::create(['name' => '  studio ', 'base_price' => 120, 'max_occupancy' => 3]);
        Http::fake([
            '*room_types*' => Http::response(['data' => [['id' => 'RT-S', 'attributes' => ['title' => 'Studio']]]]),
            '*rate_plans*' => Http::response(['data' => []]),
        ]);

        $this->artisan('channex:link-rooms')->assertSuccessful();
        $this->assertDatabaseHas('channel_mappings', ['room_type_id' => $rt->id, 'channex_room_type_id' => 'RT-S']);
    }

    public function test_read_failure_aborts_without_partial_links(): void
    {
        RoomType::create(['name' => 'Studio', 'base_price' => 120, 'max_occupancy' => 3]);
        Http::fake(['*room_types*' => Http::response(['errors' => ['unauthorized']], 401)]);

        $this->artisan('channex:link-rooms')->assertFailed();
        $this->assertDatabaseCount('channel_mappings', 0);
    }

    public function test_classifies_channel_rate_plans_by_title_into_their_columns(): void
    {
        $studio = RoomType::create(['name' => 'Studio', 'base_price' => 120, 'max_occupancy' => 3]);
        $rel = ['room_type' => ['data' => ['id' => 'RT-S']]];
        Http::fake([
            '*room_types*' => Http::response(['data' => [['id' => 'RT-S', 'attributes' => ['title' => 'Studio']]]]),
            '*rate_plans*' => Http::response(['data' => [
                // Channel plans deliberately listed FIRST: the base column must
                // still get the non-channel plan, not simply the first one.
                ['id' => 'RP-B', 'attributes' => ['title' => 'Standard Rate - Booking.com'], 'relationships' => $rel],
                ['id' => 'RP-E', 'attributes' => ['title' => 'Standard Rate - Expedia'], 'relationships' => $rel],
                ['id' => 'RP-S', 'attributes' => ['title' => 'Standard Rate'], 'relationships' => $rel],
            ]]),
        ]);

        $this->artisan('channex:link-rooms')->assertSuccessful();

        $this->assertDatabaseHas('channel_mappings', [
            'room_type_id' => $studio->id,
            'channex_room_type_id' => 'RT-S',
            'channex_rate_plan_id' => 'RP-S',
            'channex_booking_rate_plan_id' => 'RP-B',
            'channex_expedia_rate_plan_id' => 'RP-E',
        ]);
    }
}
