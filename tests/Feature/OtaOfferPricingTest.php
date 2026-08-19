<?php

namespace Tests\Feature;

use App\Jobs\PushRoomTypeAri;
use App\Models\ChannelMapping;
use App\Models\PricingOffer;
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

/**
 * OTA offers (Renato 2026-08-19): the campaign lives in the OTA's extranet;
 * Lora compensates the pushed price for the channel and window so the hotel
 * nets its canonical price — his own example: final €40 with a 20% discount
 * means the push goes out at €50.
 */
class OtaOfferPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-19 09:00:00');
        CarbonImmutable::setTestNow('2026-08-19 09:00:00');
        Http::preventStrayRequests();
        Queue::fake();
        OtaPricingPrograms::flushOffers();
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
        ]);
    }

    private function type(float $base = 40): RoomType
    {
        return RoomType::create(['name' => 'Std', 'base_price' => $base, 'max_occupancy' => 2, 'amenities' => []]);
    }

    private function mapping(RoomType $type): ChannelMapping
    {
        return ChannelMapping::create([
            'channel' => 'channex',
            'room_type_id' => $type->id,
            'channex_property_id' => 'PROP-1',
            'channex_room_type_id' => 'RT-1',
            'channex_rate_plan_id' => 'RP-BASE',
            'channex_booking_rate_plan_id' => 'RP-BOOK',
            'channex_expedia_rate_plan_id' => 'RP-EXP',
        ]);
    }

    private function offer(array $overrides = []): PricingOffer
    {
        return PricingOffer::create($overrides + [
            'name' => 'Fundjava e gushtit',
            'channel' => 'booking',
            'discount_pct' => 20,
            'starts_on' => '2026-08-21',
            'ends_on' => '2026-08-23',
            'active' => true,
        ]);
    }

    public function test_renatos_example_forty_final_with_twenty_percent_pushes_fifty(): void
    {
        $this->offer();

        $this->assertSame(0.8, OtaPricingPrograms::factorFor('booking', '2026-08-22'));
        $quote = collect(OtaPricingPrograms::quote(40.0, '2026-08-22'))->firstWhere('channel', 'booking.com');
        $this->assertSame(50.0, $quote['published_price']);
        $this->assertSame(20.0, $quote['offer_pct']);
    }

    public function test_offer_composes_multiplicatively_with_genius(): void
    {
        Setting::set('pricing_programs.booking_genius_enabled', '1', 'boolean');
        Setting::set('pricing_programs.booking_genius_pct', 10, 'number');
        $this->offer();

        // 0.9 (Genius) × 0.8 (offer) = 0.72 — never replaced, always composed.
        $this->assertSame(0.72, OtaPricingPrograms::factorFor('booking', '2026-08-22'));
        // Outside the window only Genius remains.
        $this->assertSame(0.9, OtaPricingPrograms::factorFor('booking', '2026-08-25'));
    }

    public function test_window_boundaries_are_inclusive_and_other_channels_untouched(): void
    {
        $this->offer();

        $this->assertSame(0.8, OtaPricingPrograms::factorFor('booking', '2026-08-21'));
        $this->assertSame(0.8, OtaPricingPrograms::factorFor('booking', '2026-08-23'));
        $this->assertSame(1.0, OtaPricingPrograms::factorFor('booking', '2026-08-20'));
        $this->assertSame(1.0, OtaPricingPrograms::factorFor('booking', '2026-08-24'));
        $this->assertSame(1.0, OtaPricingPrograms::factorFor('expedia', '2026-08-22'));
        $this->assertSame(1.0, OtaPricingPrograms::factorFor('airbnb', '2026-08-22'));
    }

    public function test_overlapping_offers_take_the_deepest_not_the_product(): void
    {
        $this->offer(['discount_pct' => 10]);
        $this->offer(['name' => 'Flash', 'discount_pct' => 20]);

        // 20% wins; 10% and 20% never combine into 28%.
        $this->assertSame(0.8, OtaPricingPrograms::factorFor('booking', '2026-08-22'));
    }

    public function test_inactive_offers_do_not_count_and_the_floor_holds(): void
    {
        $this->offer(['active' => false]);
        $this->assertSame(1.0, OtaPricingPrograms::factorFor('booking', '2026-08-22'));

        $this->offer(['name' => 'Thellë', 'discount_pct' => 70]);
        Setting::set('pricing_programs.booking_genius_enabled', '1', 'boolean');
        Setting::set('pricing_programs.booking_genius_pct', 10, 'number');
        // 0.9 × 0.3 = 0.27 → floored at 0.3 so a typo can never 10× the price.
        $this->assertSame(0.3, OtaPricingPrograms::factorFor('booking', '2026-08-22'));
    }

    public function test_push_divides_only_the_offer_channel_and_only_inside_the_window(): void
    {
        $type = $this->type(40);
        $this->mapping($type);
        $this->offer(); // booking, 20%, Aug 21–23

        Http::fake([
            '*availability*' => Http::response(['data' => []]),
            '*restrictions*' => Http::response(['data' => []]),
        ]);

        $ok = app(ChannelSync::class)->pushRoomType(
            $type, CarbonImmutable::parse('2026-08-20'), CarbonImmutable::parse('2026-08-22')
        );
        $this->assertTrue($ok);

        $rateFor = function (string $planId, string $date): ?int {
            $found = null;
            Http::assertSent(function ($r) use ($planId, $date, &$found) {
                if (! str_contains($r->url(), '/restrictions')) {
                    return false;
                }
                foreach ($r->data()['values'] ?? [] as $v) {
                    if ($v['rate_plan_id'] === $planId && $v['date_from'] <= $date && $v['date_to'] >= $date) {
                        $found = (int) $v['rate'];

                        return true;
                    }
                }

                return false;
            });

            return $found;
        };

        // Base plan: canonical €40 everywhere (4000 cents).
        $this->assertSame(4000, $rateFor('RP-BASE', '2026-08-20'));
        $this->assertSame(4000, $rateFor('RP-BASE', '2026-08-22'));
        // Booking plan: €40 before the window, €50 inside it.
        $this->assertSame(4000, $rateFor('RP-BOOK', '2026-08-20'));
        $this->assertSame(5000, $rateFor('RP-BOOK', '2026-08-22'));
        // Expedia plan: untouched by a booking offer.
        $this->assertSame(4000, $rateFor('RP-EXP', '2026-08-22'));
    }

    public function test_offer_writes_dispatch_a_resync_for_the_window(): void
    {
        $type = $this->type();
        $this->mapping($type);

        $offer = $this->offer();
        Queue::assertPushed(PushRoomTypeAri::class);

        // Widening the window resyncs the union of old and new dates.
        Queue::fake();
        $offer->update(['ends_on' => '2026-08-30']);
        Queue::assertPushed(PushRoomTypeAri::class);

        Queue::fake();
        $offer->delete();
        Queue::assertPushed(PushRoomTypeAri::class);
    }
}
