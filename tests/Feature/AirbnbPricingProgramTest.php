<?php

namespace Tests\Feature;

use App\Models\ChannelMapping;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\ChannelSync;
use App\Services\OtaPricingPrograms;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AirbnbPricingProgramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-01 09:00:00');
        CarbonImmutable::setTestNow('2026-07-01 09:00:00');
        Http::preventStrayRequests();
        Queue::fake();
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
        ]);
    }

    private function type(string $name = 'Std', float $base = 80): RoomType
    {
        return RoomType::create(['name' => $name, 'base_price' => $base, 'max_occupancy' => 2, 'amenities' => []]);
    }

    public function test_airbnb_factor_defaults_to_one_and_follows_the_setting(): void
    {
        // Program off (default): factor 1 -> the canonical price goes out as-is.
        $this->assertSame(1.0, OtaPricingPrograms::settings()['airbnb']['discount_factor']);

        Setting::set('pricing_programs.airbnb_host_fee_enabled', '1', 'boolean');
        Setting::set('pricing_programs.airbnb_host_fee_pct', 18.6, 'number');

        $airbnb = OtaPricingPrograms::settings()['airbnb'];
        $this->assertSame(0.814, $airbnb['discount_factor']);
        $this->assertSame(22.85, $airbnb['required_modifier_pct']);
    }

    public function test_push_sends_airbnb_plan_at_compensated_price(): void
    {
        $type = $this->type('Std', 80);
        ChannelMapping::create([
            'channel' => 'channex',
            'room_type_id' => $type->id,
            'channex_property_id' => 'PROP-1',
            'channex_room_type_id' => 'RT-1',
            'channex_rate_plan_id' => 'RP-BASE',
            'channex_airbnb_rate_plan_id' => 'RP-AIR',
        ]);
        // 20% host fee -> factor 0.8 -> €80 base publishes as €100 on Airbnb.
        Setting::set('pricing_programs.airbnb_host_fee_enabled', '1', 'boolean');
        Setting::set('pricing_programs.airbnb_host_fee_pct', 20, 'number');
        Http::fake([
            '*availability*' => Http::response(['data' => []]),
            '*restrictions*' => Http::response(['data' => []]),
        ]);

        $ok = app(ChannelSync::class)->pushRoomType(
            $type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-03')
        );

        $this->assertTrue($ok);
        // Base plan keeps the canonical price (8000 cents)...
        Http::assertSent(fn ($r) => str_contains($r->url(), '/restrictions')
            && collect($r->data()['values'])->contains(fn ($v) => $v['rate_plan_id'] === 'RP-BASE' && (int) $v['rate'] === 8000));
        // ...while the Airbnb plan gets the compensated price (10000 cents).
        Http::assertSent(fn ($r) => str_contains($r->url(), '/restrictions')
            && collect($r->data()['values'])->contains(fn ($v) => $v['rate_plan_id'] === 'RP-AIR' && (int) $v['rate'] === 10000));
    }

    public function test_push_omits_airbnb_plan_when_not_linked(): void
    {
        $type = $this->type('Std', 80);
        ChannelMapping::create([
            'channel' => 'channex',
            'room_type_id' => $type->id,
            'channex_property_id' => 'PROP-1',
            'channex_room_type_id' => 'RT-1',
            'channex_rate_plan_id' => 'RP-BASE',
            // channex_airbnb_rate_plan_id intentionally null (pre-feature rows).
        ]);
        Setting::set('pricing_programs.airbnb_host_fee_enabled', '1', 'boolean');
        Http::fake([
            '*availability*' => Http::response(['data' => []]),
            '*restrictions*' => Http::response(['data' => []]),
        ]);

        $ok = app(ChannelSync::class)->pushRoomType(
            $type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-01')
        );

        $this->assertTrue($ok);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/restrictions')
            && collect($r->data()['values'])->contains(fn ($v) => ($v['rate_plan_id'] ?? null) === null));
        // Exactly one restrictions push: the base plan only.
        $this->assertSame(1, collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/restrictions'))
            ->count());
    }

    public function test_link_rooms_fills_the_airbnb_rate_plan_column(): void
    {
        $type = $this->type('Std');
        Http::fake([
            '*room_types*' => Http::response(['data' => [
                ['id' => 'RT-1', 'attributes' => ['title' => 'Std']],
            ]]),
            '*rate_plans*' => Http::response(['data' => [
                ['id' => 'RP-BASE', 'attributes' => ['title' => ChannelSync::RATE_PLAN_TITLE_BASE], 'relationships' => ['room_type' => ['data' => ['id' => 'RT-1']]]],
                ['id' => 'RP-BOOK', 'attributes' => ['title' => ChannelSync::RATE_PLAN_TITLE_BOOKING], 'relationships' => ['room_type' => ['data' => ['id' => 'RT-1']]]],
                ['id' => 'RP-EXP', 'attributes' => ['title' => ChannelSync::RATE_PLAN_TITLE_EXPEDIA], 'relationships' => ['room_type' => ['data' => ['id' => 'RT-1']]]],
                ['id' => 'RP-AIR', 'attributes' => ['title' => ChannelSync::RATE_PLAN_TITLE_AIRBNB], 'relationships' => ['room_type' => ['data' => ['id' => 'RT-1']]]],
            ]]),
        ]);

        $this->artisan('channex:link-rooms')->assertExitCode(0);

        $mapping = ChannelMapping::where('room_type_id', $type->id)->firstOrFail();
        $this->assertSame('RP-BASE', $mapping->channex_rate_plan_id);
        $this->assertSame('RP-BOOK', $mapping->channex_booking_rate_plan_id);
        $this->assertSame('RP-EXP', $mapping->channex_expedia_rate_plan_id);
        $this->assertSame('RP-AIR', $mapping->channex_airbnb_rate_plan_id);
    }
}
