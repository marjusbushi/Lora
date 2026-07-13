<?php

namespace Tests\Feature;

use App\Models\CompRate;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\MarketRates;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MarketRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-13 09:00:00'); // a Monday — 3x_week fetches
        CarbonImmutable::setTestNow('2026-07-13 09:00:00');
        Http::preventStrayRequests();
    }

    private function enable(array $competitors = ['Hotel Piccolino', 'Vila Duka']): void
    {
        Setting::set('market_rates.enabled', '1', 'boolean');
        Setting::set('market_rates.api_key', 'serp-test-key', 'text');
        Setting::set('market_rates.competitors', $competitors, 'json');
    }

    /** One SerpAPI google_hotels payload usable for every date request. */
    private function fakeSerp(): void
    {
        Http::fake(['serpapi.com/*' => Http::response(['properties' => [
            ['name' => 'Hotel Piccolino Saranda', 'rate_per_night' => ['lowest' => '€75', 'extracted_lowest' => 75]],
            ['name' => 'VILA DUKA', 'rate_per_night' => ['lowest' => '€64']], // no extracted -> string fallback
            ['name' => 'Grand Resort Unrelated', 'rate_per_night' => ['extracted_lowest' => 300]], // not in comp-set
            ['name' => 'Villa NoPrice', 'rate_per_night' => []], // no price -> skipped
        ]])]);
    }

    // -- command --------------------------------------------------------------

    public function test_command_is_a_no_op_when_disabled_or_without_key(): void
    {
        // disabled entirely (preventStrayRequests => any HTTP would throw)
        $this->artisan('market:fetch-rates')->assertSuccessful();

        // enabled but no key
        Setting::set('market_rates.enabled', '1', 'boolean');
        $this->artisan('market:fetch-rates')->assertSuccessful();

        $this->assertSame(0, CompRate::count());
    }

    public function test_command_stores_matched_competitor_prices(): void
    {
        $this->enable();
        $this->fakeSerp();

        $this->artisan('market:fetch-rates', ['--days' => 2])->assertSuccessful();

        // 2 dates x 2 matched competitors; the unrelated + priceless ones skipped
        $this->assertSame(4, CompRate::count());
        $this->assertDatabaseHas('comp_rates', [
            'competitor' => 'Hotel Piccolino',
            'date' => '2026-07-13',
            'price' => 75.00,
            'snapshot_date' => '2026-07-13',
        ]);
        // case-insensitive match + price parsed from the "€64" string form
        $this->assertDatabaseHas('comp_rates', ['competitor' => 'Vila Duka', 'price' => 64.00]);
        // one request per date
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'serpapi.com')
            && $r['engine'] === 'google_hotels'
            && $r['check_in_date'] === '2026-07-13'
            && $r['check_out_date'] === '2026-07-14');
    }

    public function test_rerun_updates_the_same_snapshot_instead_of_duplicating(): void
    {
        $this->enable();
        $this->fakeSerp();

        $this->artisan('market:fetch-rates', ['--days' => 1])->assertSuccessful();
        $this->artisan('market:fetch-rates', ['--days' => 1])->assertSuccessful();

        $this->assertSame(2, CompRate::count()); // 1 date x 2 competitors, once
    }

    public function test_scheduled_run_respects_3x_week_off_days(): void
    {
        Carbon::setTestNow('2026-07-14 05:30:00'); // Tuesday — off-day
        CarbonImmutable::setTestNow('2026-07-14 05:30:00');
        $this->enable();

        $this->artisan('market:fetch-rates', ['--scheduled' => true])->assertSuccessful();

        $this->assertSame(0, CompRate::count()); // skipped, zero HTTP (preventStray guards)
    }

    // -- summary ---------------------------------------------------------------

    public function test_summary_uses_latest_snapshot_and_computes_median(): void
    {
        // stale snapshot (must be ignored)
        foreach ([['A', 50], ['B', 60]] as [$c, $p]) {
            CompRate::create(['competitor' => $c, 'date' => '2026-07-20', 'price' => $p, 'snapshot_date' => '2026-07-10']);
        }
        // latest snapshot: prices 70/80/95 -> median 80
        foreach ([['A', 70], ['B', 80], ['C', 95]] as [$c, $p]) {
            CompRate::create(['competitor' => $c, 'date' => '2026-07-20', 'price' => $p, 'snapshot_date' => '2026-07-12']);
        }

        $summary = MarketRates::summaryForRange(CarbonImmutable::parse('2026-07-20'), CarbonImmutable::parse('2026-07-20'));

        $this->assertSame(
            ['median' => 80.0, 'min' => 70.0, 'max' => 95.0, 'count' => 3],
            $summary['2026-07-20'],
        );
    }

    public function test_summary_even_count_averages_the_middle_pair(): void
    {
        foreach ([['A', 70], ['B', 90]] as [$c, $p]) {
            CompRate::create(['competitor' => $c, 'date' => '2026-07-21', 'price' => $p, 'snapshot_date' => '2026-07-12']);
        }

        $summary = MarketRates::summaryForRange(CarbonImmutable::parse('2026-07-21'), CarbonImmutable::parse('2026-07-21'));

        $this->assertSame(80.0, $summary['2026-07-21']['median']);
    }

    // -- settings --------------------------------------------------------------

    public function test_settings_save_roundtrip_and_key_retention(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payload = [
            'enabled' => true,
            'api_key' => 'serp-live-key',
            'clear_key' => false,
            'competitors' => ['Hotel Piccolino', '  Vila Duka  ', ''],
            'frequency' => 'daily',
            'search_query' => 'Hotels Sarande Albania',
        ];
        $this->actingAs($admin)->put(route('settings.market-rates'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue((bool) Setting::get('market_rates.enabled'));
        $this->assertSame('serp-live-key', Setting::get('market_rates.api_key'));
        $this->assertSame(['Hotel Piccolino', 'Vila Duka'], Setting::get('market_rates.competitors'));
        $this->assertSame('daily', Setting::get('market_rates.frequency'));

        // empty api_key on a later save keeps the stored key
        $this->actingAs($admin)->put(route('settings.market-rates'), array_merge($payload, ['api_key' => '']))->assertRedirect();
        $this->assertSame('serp-live-key', Setting::get('market_rates.api_key'));

        // clear_key removes it
        $this->actingAs($admin)->put(route('settings.market-rates'), array_merge($payload, ['api_key' => '', 'clear_key' => true]))->assertRedirect();
        $this->assertSame('', Setting::get('market_rates.api_key'));
    }

    // -- smart pricing surface ---------------------------------------------------

    public function test_smart_pricing_page_ships_market_summary_without_touching_suggestions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        RoomType::create(['name' => 'Deluxe', 'base_price' => 80, 'max_occupancy' => 2]);
        CompRate::create(['competitor' => 'A', 'date' => '2026-07-20', 'price' => 90, 'snapshot_date' => '2026-07-13']);

        $this->withoutVite();
        $this->actingAs($admin)
            ->get(route('pricing.smart.index', ['month' => '2026-07-01']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pricing/Smart')
                ->has('market')
                ->where('market.2026-07-20.median', 90)
                ->where('market.2026-07-20.count', 1)
                ->has('days'));
    }
}
