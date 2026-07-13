<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannexBootstrapRoomsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://app.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-PROD',
        ]);
    }

    private function type(string $name, int $rooms = 1, int $occ = 2): RoomType
    {
        $type = RoomType::create(['name' => $name, 'base_price' => 80, 'max_occupancy' => $occ, 'amenities' => []]);
        for ($i = 1; $i <= $rooms; $i++) {
            Room::create(['room_type_id' => $type->id, 'room_number' => "{$name}-{$i}", 'floor' => 1, 'status' => 'available']);
        }

        return $type;
    }

    /** GET list => the given (empty by default) data; POST create => a fresh id. */
    private function fake(array $roomTypes = [], array $ratePlans = []): void
    {
        Http::fake(function ($request) use ($roomTypes, $ratePlans) {
            $url = $request->url();
            $post = $request->method() === 'POST';
            if (str_contains($url, '/room_types')) {
                return $post
                    ? Http::response(['data' => ['id' => 'RT-'.substr(md5($request->body()), 0, 6)]], 201)
                    : Http::response(['data' => $roomTypes]);
            }
            if (str_contains($url, '/rate_plans')) {
                return $post
                    ? Http::response(['data' => ['id' => 'RP-'.substr(md5($request->body()), 0, 6)]], 201)
                    : Http::response(['data' => $ratePlans]);
            }

            return Http::response(['data' => []]);
        });
    }

    private function postCount(string $needle): int
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), $needle))
            ->count();
    }

    public function test_creates_one_room_type_and_three_rate_plans_per_pms_type_when_empty(): void
    {
        $this->type('Twin', 3, 2);
        $this->type('Double', 2, 2);
        $this->fake(); // empty Channex account

        $this->artisan('channex:bootstrap-rooms')->assertSuccessful();

        $this->assertSame(2, $this->postCount('/room_types'), 'one room type created per PMS type');
        $this->assertSame(6, $this->postCount('/rate_plans'), 'base + booking + expedia plan per PMS type');
        // room type carries the PMS name + physical room count + occupancy
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/room_types')
            && ($r->data()['room_type']['title'] ?? null) === 'Twin'
            && (int) ($r->data()['room_type']['count_of_rooms'] ?? 0) === 3);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/room_types')
            && ($r->data()['room_type']['title'] ?? null) === 'Double'
            && (int) ($r->data()['room_type']['count_of_rooms'] ?? 0) === 2);
        // the three plan roles carry their distinguishing titles
        foreach (['Standard Rate', 'Standard Rate - Booking.com', 'Standard Rate - Expedia'] as $planTitle) {
            Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/rate_plans')
                && ($r->data()['rate_plan']['title'] ?? null) === $planTitle);
        }
    }

    public function test_is_idempotent_when_all_three_rate_plans_already_exist(): void
    {
        $this->type('Twin', 3, 2);
        $rel = ['room_type' => ['data' => ['id' => 'RT-1']]];
        $this->fake(
            roomTypes: [['id' => 'RT-1', 'attributes' => ['title' => 'Twin']]],
            ratePlans: [
                ['id' => 'RP-1', 'attributes' => ['title' => 'Standard Rate'], 'relationships' => $rel],
                ['id' => 'RP-B', 'attributes' => ['title' => 'Standard Rate - Booking.com'], 'relationships' => $rel],
                ['id' => 'RP-E', 'attributes' => ['title' => 'Standard Rate - Expedia'], 'relationships' => $rel],
            ],
        );

        $this->artisan('channex:bootstrap-rooms')->assertSuccessful();

        $this->assertSame(0, $this->postCount('/room_types'), 'existing room type not re-created');
        $this->assertSame(0, $this->postCount('/rate_plans'), 'existing rate plans not re-created');
    }

    public function test_creates_only_the_missing_channel_plans_when_base_exists(): void
    {
        // Legacy prod state: the room type already has its untitled-role base
        // plan ("Standard Rate") but none of the per-channel plans yet.
        $this->type('Twin', 3, 2);
        $this->fake(
            roomTypes: [['id' => 'RT-1', 'attributes' => ['title' => 'Twin']]],
            ratePlans: [['id' => 'RP-1', 'attributes' => ['title' => 'Standard Rate'], 'relationships' => ['room_type' => ['data' => ['id' => 'RT-1']]]]],
        );

        $this->artisan('channex:bootstrap-rooms')->assertSuccessful();

        $this->assertSame(0, $this->postCount('/room_types'));
        $this->assertSame(2, $this->postCount('/rate_plans'), 'only booking + expedia plans created');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/rate_plans')
            && ($r->data()['rate_plan']['title'] ?? null) === 'Standard Rate - Booking.com'
            && ($r->data()['rate_plan']['room_type_id'] ?? null) === 'RT-1');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/rate_plans')
            && ($r->data()['rate_plan']['title'] ?? null) === 'Standard Rate - Expedia'
            && ($r->data()['rate_plan']['room_type_id'] ?? null) === 'RT-1');
    }

    public function test_dry_run_creates_nothing(): void
    {
        $this->type('Twin', 3, 2);
        $this->fake();

        $this->artisan('channex:bootstrap-rooms', ['--dry' => true])->assertSuccessful();

        $this->assertSame(0, $this->postCount('/room_types'));
        $this->assertSame(0, $this->postCount('/rate_plans'));
    }

    public function test_fails_when_not_configured(): void
    {
        config(['services.channex.api_key' => '']);
        $this->type('Twin');

        $this->artisan('channex:bootstrap-rooms')->assertFailed(); // preventStrayRequests => no HTTP attempted
    }
}
