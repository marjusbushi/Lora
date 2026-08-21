<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\User;
use App\Services\ChannelSync;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDynamicFromPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-11 10:00:00', 'Europe/Tirane'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function roomType(float $basePrice = 100): RoomType
    {
        return RoomType::create([
            'name' => 'Deluxe',
            'description' => 'Dynamic public price test',
            'base_price' => $basePrice,
            'max_occupancy' => 2,
            'amenities' => [],
        ]);
    }

    private function room(RoomType $roomType, string $number, string $status = 'available'): Room
    {
        return Room::create([
            'room_type_id' => $roomType->id,
            'room_number' => $number,
            'floor' => 1,
            'status' => $status,
        ]);
    }

    private function seasonRate(
        RoomType $roomType,
        string $name,
        string $from,
        string $to,
        float $price,
        int $priority = 10,
    ): void {
        $season = Season::create([
            'name' => $name,
            'start_date' => $from,
            'end_date' => $to,
            'priority' => $priority,
        ]);

        $season->rates()->create([
            'room_type_id' => $roomType->id,
            'price' => $price,
        ]);
    }

    /** @return array<string, mixed> */
    private function publicRoomTypeProps(string $routeName, RoomType $roomType): array
    {
        $response = $this->get(route($routeName))->assertOk();
        $page = $response->viewData('page');
        $props = collect($page['props']['roomTypes'] ?? [])->firstWhere('id', $roomType->id);

        $this->assertNotNull($props, "{$routeName} must expose the room type");

        return $props;
    }

    public function test_home_rooms_and_book_expose_a_separate_effective_from_price_for_the_next_sixty_nights(): void
    {
        $roomType = $this->roomType(100);
        $this->room($roomType, '101');

        $expired = today()->subDays(30);
        $this->seasonRate(
            $roomType,
            'Expired bargain',
            $expired->toDateString(),
            $expired->copy()->addDays(5)->toDateString(),
            10,
        );

        $overriddenDate = today()->addDays(10)->toDateString();
        $this->seasonRate($roomType, 'Future season', $overriddenDate, $overriddenDate, 50);
        RateOverride::create([
            'room_type_id' => $roomType->id,
            'date' => $overriddenDate,
            'price' => 70,
        ]);

        // Both edges are explicit: today+59 is included, while today+60 is not.
        RateOverride::create([
            'room_type_id' => $roomType->id,
            'date' => today()->addDays(59)->toDateString(),
            'price' => 60,
        ]);
        RateOverride::create([
            'room_type_id' => $roomType->id,
            'date' => today()->addDays(60)->toDateString(),
            'price' => 5,
        ]);

        foreach (['website.home', 'website.rooms', 'website.book'] as $routeName) {
            $props = $this->publicRoomTypeProps($routeName, $roomType);

            $this->assertArrayHasKey('from_price', $props, "{$routeName} must expose from_price separately");
            $this->assertSame(100.0, (float) $props['base_price'], "{$routeName} must preserve the PMS base price");
            $this->assertSame(60.0, (float) $props['from_price'], "{$routeName} must use override > season > base inside the 60-night horizon");
        }
    }

    public function test_from_price_ignores_a_sold_out_cheaper_night_and_maintenance_inventory(): void
    {
        $roomType = $this->roomType(100);
        $sellableRoom = $this->room($roomType, '201');
        $this->room($roomType, '202', 'maintenance');

        $soldOutDate = today()->addDays(2)->toDateString();
        RateOverride::create([
            'room_type_id' => $roomType->id,
            'date' => $soldOutDate,
            'price' => 40,
        ]);
        RateOverride::create([
            'room_type_id' => $roomType->id,
            'date' => today()->addDays(4)->toDateString(),
            'price' => 75,
        ]);

        Reservation::create([
            'room_id' => $sellableRoom->id,
            'guest_id' => Guest::create([
                'first_name' => 'Sold',
                'last_name' => 'Out',
                'email' => 'sold-out@example.test',
            ])->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => $soldOutDate,
            'check_out_date' => today()->addDays(3)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 40,
            'adults' => 1,
            'channel' => 'direct',
        ]);

        $props = $this->publicRoomTypeProps('website.home', $roomType);

        $this->assertArrayHasKey('from_price', $props);
        $this->assertSame(75.0, (float) $props['from_price']);
    }

    public function test_public_from_price_exact_booking_quote_and_ota_rate_share_the_same_effective_override(): void
    {
        $roomType = $this->roomType(100);
        $this->room($roomType, '301');

        $date = today()->addDays(5);
        $dateString = $date->toDateString();
        $this->seasonRate($roomType, 'Parity season', $dateString, $dateString, 80);
        RateOverride::create([
            'room_type_id' => $roomType->id,
            'date' => $dateString,
            'price' => 65,
        ]);

        $quote = $this->postJson(route('website.book.check'), [
            'check_in' => $dateString,
            'check_out' => $date->copy()->addDay()->toDateString(),
            'room_type_id' => $roomType->id,
        ])->assertOk();

        $quote->assertJsonPath('room_types.0.price_per_night', 65)
            ->assertJsonPath('room_types.0.total_price', 65);

        $otaRates = app(ChannelSync::class)->priceByDate(
            $roomType,
            CarbonImmutable::parse($dateString),
            CarbonImmutable::parse($dateString),
        );
        $this->assertSame(65.0, $otaRates[$dateString]);

        $publicProps = $this->publicRoomTypeProps('website.home', $roomType);
        $this->assertArrayHasKey('from_price', $publicProps);
        $this->assertSame(65.0, (float) $publicProps['from_price']);
        $this->assertSame((float) $quote->json('room_types.0.total_price'), $otaRates[$dateString]);
        $this->assertSame((float) $publicProps['from_price'], $otaRates[$dateString]);
    }
}
