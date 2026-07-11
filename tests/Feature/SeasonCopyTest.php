<?php

namespace Tests\Feature;

use App\Jobs\PushRoomTypeAri;
use App\Models\AuditLog;
use App\Models\ChannelMapping;
use App\Models\RateOverride;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use App\Models\Setting;
use App\Models\User;
use App\Services\PricingRulesVersion;
use App\Services\SeasonCopyService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeasonCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-10 10:00:00');
        CarbonImmutable::setTestNow('2026-07-10 10:00:00');
        Http::preventStrayRequests();
        Queue::fake();
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.property_id' => 'PROP-1',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function roomType(string $name, float $base, ?float $min = 1, ?float $max = 500): RoomType
    {
        return RoomType::create([
            'name' => $name,
            'base_price' => $base,
            'min_price' => $min,
            'max_price' => $max,
            'max_occupancy' => 2,
            'amenities' => [],
        ]);
    }

    private function season(
        string $name,
        string $start,
        string $end,
        int $priority = 10,
        array $rates = [],
    ): Season {
        $season = Season::create([
            'name' => $name,
            'start_date' => $start,
            'end_date' => $end,
            'priority' => $priority,
        ]);
        foreach ($rates as $roomTypeId => $price) {
            SeasonRate::create([
                'season_id' => $season->id,
                'room_type_id' => $roomTypeId,
                'price' => $price,
            ]);
        }

        return $season;
    }

    private function map(RoomType $roomType): void
    {
        ChannelMapping::create([
            'channel' => 'channex',
            'room_type_id' => $roomType->id,
            'channex_property_id' => 'PROP-1',
            'channex_room_type_id' => 'RT-'.$roomType->id,
            'channex_rate_plan_id' => 'RP-'.$roomType->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function preview(int $sourceYear, int $targetYear, float $uplift): array
    {
        return $this->actingAs($this->admin)
            ->postJson(route('pricing.seasons.copy.preview'), [
                'source_year' => $sourceYear,
                'target_year' => $targetYear,
                'uplift_pct' => $uplift,
            ])
            ->assertOk()
            ->json();
    }

    public function test_preview_is_read_only_materializes_fallbacks_and_supports_cross_year_seasons(): void
    {
        $apartment = $this->roomType('Apartment', 80);
        $studio = $this->roomType('Studio', 70);
        $september = $this->season('Shtator 2026', '2026-09-01', '2026-09-30', 10, [
            $apartment->id => 100,
        ]);
        $this->season('Fundvit', '2026-12-20', '2027-01-10', 20, [
            $apartment->id => 120,
            $studio->id => 90,
        ]);
        Setting::set('channex.sell_until_date', '2027-10-31');
        RateOverride::create([
            'date' => '2027-09-15',
            'room_type_id' => $apartment->id,
            'price' => 111,
            'created_by' => $this->admin->id,
        ]);

        $rulesVersion = (int) Setting::get('pricing.rules_version', 0);
        $preview = $this->preview(2026, 2027, 7);

        $this->assertSame('ready', $preview['state']);
        $this->assertSame(2026, $preview['source_year']);
        $this->assertSame(2027, $preview['target_year']);
        $this->assertEquals(7.0, $preview['uplift_pct']);
        $this->assertSame($rulesVersion, $preview['rules_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['preview_hash']);
        $this->assertSame(1, $preview['override_count']);
        $this->assertSame('2027-10-31', $preview['ota_publish_until']);
        $this->assertSame([], $preview['conflicts']);
        $this->assertArrayNotHasKey('_actions', $preview);

        $copiedSeptember = collect($preview['seasons'])->firstWhere('source_season_id', $september->id);
        $this->assertSame('Shtator 2027', $copiedSeptember['target_name']);
        $this->assertSame('2027-09-01', $copiedSeptember['start_date']);
        $this->assertSame('2027-09-30', $copiedSeptember['end_date']);
        $apartmentRate = collect($copiedSeptember['rates'])->firstWhere('room_type_id', $apartment->id);
        $studioRate = collect($copiedSeptember['rates'])->firstWhere('room_type_id', $studio->id);
        $this->assertSame('season', $apartmentRate['source_kind']);
        $this->assertEquals(100.0, $apartmentRate['source_price']);
        $this->assertEquals(107.0, $apartmentRate['target_price']);
        $this->assertSame('base', $studioRate['source_kind']);
        $this->assertEquals(70.0, $studioRate['source_price']);
        $this->assertEquals(74.9, $studioRate['target_price']);

        $crossYear = collect($preview['seasons'])->firstWhere('source_name', 'Fundvit');
        $this->assertSame('Fundvit 2027', $crossYear['target_name']);
        $this->assertSame('2027-12-20', $crossYear['start_date']);
        $this->assertSame('2028-01-10', $crossYear['end_date']);

        $this->assertSame(2, Season::count(), 'preview must not create target seasons');
        $this->assertSame(3, SeasonRate::count(), 'preview must not create target rates');
        $this->assertSame(0, AuditLog::where('action', 'pricing.seasons_copy')->count());
        $this->assertSame($rulesVersion, (int) Setting::get('pricing.rules_version', 0));
        Queue::assertNothingPushed();
    }

    public function test_confirmed_apply_is_atomic_audited_versioned_queued_and_idempotent(): void
    {
        $apartment = $this->roomType('Apartment', 80);
        $studio = $this->roomType('Studio', 70);
        $this->map($apartment);
        $this->map($studio);
        $this->season('Shtator 2026', '2026-09-01', '2026-09-30', 10, [
            $apartment->id => 100,
        ]);
        $this->season('Fundvit', '2026-12-20', '2027-01-10', 20, [
            $apartment->id => 120,
            $studio->id => 90,
        ]);

        $preview = $this->preview(2026, 2027, 7);
        $beforeVersion = $preview['rules_version'];

        $applied = $this->actingAs($this->admin)
            ->postJson(route('pricing.seasons.copy.apply'), [
                'source_year' => 2026,
                'target_year' => 2027,
                'uplift_pct' => 7,
                'rules_version' => $preview['rules_version'],
                'preview_hash' => $preview['preview_hash'],
                'confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('state', 'applied')
            ->assertJsonPath('sync_queued', true)
            ->assertJsonPath('sync_queue_count', 2)
            ->json();

        $this->assertSame($beforeVersion + 1, $applied['rules_version']);
        $this->assertSame($beforeVersion + 1, (int) Setting::get('pricing.rules_version', 0));
        $this->assertSame(4, Season::count());
        $this->assertSame(7, SeasonRate::count(), 'all active types are materialized for both target seasons');
        $target = Season::where('name', 'Shtator 2027')->with('rates')->firstOrFail();
        $this->assertEquals(107.0, (float) $target->rates->firstWhere('room_type_id', $apartment->id)->price);
        $this->assertEquals(74.9, (float) $target->rates->firstWhere('room_type_id', $studio->id)->price);

        $audit = AuditLog::where('action', 'pricing.seasons_copy')->sole();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame(2, $audit->properties['created_seasons']);
        $this->assertSame(4, $audit->properties['created_rates']);
        $this->assertCount(2, $audit->properties['created_season_ids']);
        $this->assertSame('Shtator 2027', $audit->properties['copied_seasons'][0]['name']);
        $this->assertCount(2, $audit->properties['copied_seasons'][0]['rates']);
        $this->assertSame('2027-09-01', $audit->properties['target_from']);
        $this->assertSame('2028-01-10', $audit->properties['target_to']);

        Queue::assertPushed(PushRoomTypeAri::class, 2);
        Queue::assertPushed(PushRoomTypeAri::class, fn (PushRoomTypeAri $job) => $job->from === '2027-09-01' && $job->to === '2028-01-10'
        );

        Queue::fake();
        $retryPreview = $this->preview(2026, 2027, 7);
        $this->assertSame('no_changes', $retryPreview['state']);
        $retryVersion = $retryPreview['rules_version'];
        $this->actingAs($this->admin)
            ->postJson(route('pricing.seasons.copy.apply'), [
                'source_year' => 2026,
                'target_year' => 2027,
                'uplift_pct' => 7,
                'rules_version' => $retryPreview['rules_version'],
                'preview_hash' => $retryPreview['preview_hash'],
                'confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('state', 'no_changes');

        $this->assertSame($retryVersion, (int) Setting::get('pricing.rules_version', 0));
        $this->assertSame(1, AuditLog::where('action', 'pricing.seasons_copy')->count());
        Queue::assertNothingPushed();
    }

    public function test_queue_insertion_failure_is_reported_as_a_sync_warning_after_copy_commits(): void
    {
        $type = $this->roomType('Studio', 80);
        $this->map($type);
        $this->season('Shtator 2026', '2026-09-01', '2026-09-30', 10, [
            $type->id => 100,
        ]);
        $preview = $this->preview(2026, 2027, 0);

        $service = \Mockery::mock(SeasonCopyService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('queueRateSync')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(SeasonCopyService::class, $service);

        $this->actingAs($this->admin)
            ->postJson(route('pricing.seasons.copy.apply'), [
                'source_year' => 2026,
                'target_year' => 2027,
                'uplift_pct' => 0,
                'rules_version' => $preview['rules_version'],
                'preview_hash' => $preview['preview_hash'],
                'confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('state', 'applied')
            ->assertJsonPath('sync_queued', false)
            ->assertJsonPath('sync_queue_count', 0);

        $this->assertDatabaseHas('seasons', [
            'name' => 'Shtator 2027',
            'start_date' => '2027-09-01 00:00:00',
            'end_date' => '2027-09-30 00:00:00',
        ]);
        $this->assertSame(1, AuditLog::where('action', 'pricing.seasons_copy')->count());
        Queue::assertNothingPushed();
    }

    public function test_stale_preview_and_missing_confirmation_cannot_write(): void
    {
        $type = $this->roomType('Studio', 80);
        $source = $this->season('Shtator', '2026-09-01', '2026-09-30', 10, [$type->id => 100]);
        $preview = $this->preview(2026, 2027, 0);

        $this->actingAs($this->admin)
            ->postJson(route('pricing.seasons.copy.apply'), [
                'source_year' => 2026,
                'target_year' => 2027,
                'uplift_pct' => 0,
                'rules_version' => $preview['rules_version'],
                'preview_hash' => $preview['preview_hash'],
                'confirmed' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmed');

        DB::transaction(function () use ($source, $type) {
            $version = PricingRulesVersion::lock();
            SeasonRate::where('season_id', $source->id)
                ->where('room_type_id', $type->id)
                ->update(['price' => 110]);
            PricingRulesVersion::increment($version);
        });

        $this->actingAs($this->admin)
            ->postJson(route('pricing.seasons.copy.apply'), [
                'source_year' => 2026,
                'target_year' => 2027,
                'uplift_pct' => 0,
                'rules_version' => $preview['rules_version'],
                'preview_hash' => $preview['preview_hash'],
                'confirmed' => true,
            ])
            ->assertConflict()
            ->assertJsonPath('state', 'stale');

        $this->assertSame(1, Season::count());
        $this->assertSame(0, AuditLog::where('action', 'pricing.seasons_copy')->count());
        Queue::assertNothingPushed();
    }

    public function test_uplift_outside_the_safe_ui_range_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('pricing.seasons.copy.preview'), [
                'source_year' => 2026,
                'target_year' => 2027,
                'uplift_pct' => 101,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('uplift_pct');

        $this->assertSame(0, Season::count());
        Queue::assertNothingPushed();
    }

    public function test_ambiguous_source_target_overlap_leap_day_and_bounds_are_blocked(): void
    {
        $type = $this->roomType('Apartment', 100, 80, 105);
        $this->season('A', '2026-09-01', '2026-09-20', 10, [$type->id => 100]);
        $this->season('B', '2026-09-10', '2026-09-30', 10, [$type->id => 100]);
        $this->season('Ekzistues', '2027-08-25', '2027-09-05', 50, [$type->id => 90]);

        $preview = $this->preview(2026, 2027, 10);
        $this->assertSame('conflict', $preview['state']);
        $this->assertTrue(collect($preview['conflicts'])->contains(fn (string $message) => str_contains($message, 'të njëjtin prioritet')));
        $this->assertTrue(collect($preview['conflicts'])->contains(fn (string $message) => str_contains($message, 'mbivendoset me sezonin ekzistues')));
        $this->assertTrue(collect($preview['conflicts'])->contains(fn (string $message) => str_contains($message, 'kalon maksimumin')));

        $this->actingAs($this->admin)
            ->postJson(route('pricing.seasons.copy.apply'), [
                'source_year' => 2026,
                'target_year' => 2027,
                'uplift_pct' => 10,
                'rules_version' => $preview['rules_version'],
                'preview_hash' => $preview['preview_hash'],
                'confirmed' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('state', 'conflict');
        $this->assertSame(3, Season::count());

        Season::query()->delete();
        $this->season('Shkurt 2024', '2024-02-29', '2024-03-02', 10, [$type->id => 100]);
        $leap = $this->preview(2024, 2025, 0);
        $this->assertSame('conflict', $leap['state']);
        $this->assertTrue(collect($leap['conflicts'])->contains(fn (string $message) => str_contains($message, '29 Shkurt')));
    }

    public function test_audit_failure_rolls_back_the_entire_copy(): void
    {
        $type = $this->roomType('Studio', 80);
        $this->season('Shtator', '2026-09-01', '2026-09-30', 10, [$type->id => 100]);
        $preview = $this->preview(2026, 2027, 0);
        $beforeVersion = $preview['rules_version'];

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER fail_season_copy_audit
                BEFORE INSERT ON audit_logs FOR EACH ROW
                BEGIN
                    IF NEW.action = 'pricing.seasons_copy' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced audit failure';
                    END IF;
                END
            SQL);
        } else {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER fail_season_copy_audit
                BEFORE INSERT ON audit_logs
                WHEN NEW.action = 'pricing.seasons_copy'
                BEGIN
                    SELECT RAISE(ABORT, 'forced audit failure');
                END;
            SQL);
        }

        $this->actingAs($this->admin)
            ->postJson(route('pricing.seasons.copy.apply'), [
                'source_year' => 2026,
                'target_year' => 2027,
                'uplift_pct' => 0,
                'rules_version' => $preview['rules_version'],
                'preview_hash' => $preview['preview_hash'],
                'confirmed' => true,
            ])
            ->assertServerError();

        $this->assertSame(1, Season::count(), 'target season must roll back with the failed audit');
        $this->assertSame(1, SeasonRate::count(), 'target rates must roll back with the failed audit');
        $this->assertSame($beforeVersion, (int) Setting::get('pricing.rules_version', 0));
        Queue::assertNothingPushed();
    }
}
