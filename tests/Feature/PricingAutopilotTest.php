<?php

namespace Tests\Feature;

use App\Jobs\PushRoomTypeAri;
use App\Models\Guest;
use App\Models\PricingAutopilotLog;
use App\Models\PricingManualProtection;
use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\TenantKey;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Task #196 (Copa 5): the guarded autopilot. OFF by default; every guard
 * (materiality, daily cap, pause window, manual protection, sanity band)
 * tested; every change logged and revertible.
 */
class PricingAutopilotTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $type;

    /** @var Room[] */
    private array $rooms;

    private function fullNight(int $daysAhead = 15): string
    {
        $d = Carbon::today()->addDays($daysAhead);
        while (in_array((int) $d->dayOfWeekIso, [5, 6], true)) {
            $d->addDay();
        }
        $date = $d->toDateString();
        foreach ($this->rooms as $room) {
            Reservation::create([
                'room_id' => $room->id,
                'guest_id' => Guest::create(['first_name' => 'G', 'last_name' => 'X'])->id,
                'created_by' => User::factory()->create()->id,
                'check_in_date' => $date,
                'check_out_date' => $d->copy()->addDay()->toDateString(),
                'status' => 'confirmed',
                'total_amount' => 100,
                'adults' => 1,
                'channel' => 'direct',
            ]);
        }

        return $date; // 100% occupancy → engine suggests +30 (weekday)
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = RoomType::create([
            'name' => 'Twin',
            'base_price' => 100,
            'min_price' => 50,
            'max_price' => 200,
            'max_occupancy' => 2,
            'amenities' => [],
        ]);
        $this->rooms = [
            Room::create(['room_number' => 'AP1', 'room_type_id' => $this->type->id, 'floor' => 1, 'status' => 'available']),
            Room::create(['room_number' => 'AP2', 'room_type_id' => $this->type->id, 'floor' => 1, 'status' => 'available']),
        ];
    }

    private function logFor(string $date): ?PricingAutopilotLog
    {
        return PricingAutopilotLog::whereDate('date', $date)->first();
    }

    private function enable(array $overrides = []): void
    {
        Setting::set('pricing.autopilot.enabled', '1');
        foreach ($overrides as $k => $v) {
            Setting::set('pricing.autopilot.'.$k, $v);
        }
    }

    public function test_off_by_default_writes_nothing(): void
    {
        $this->fullNight();
        $this->artisan('pricing:autopilot')->assertSuccessful();

        $this->assertSame(0, RateOverride::count());
        $this->assertSame(0, PricingAutopilotLog::count());
    }

    public function test_enabled_applies_engine_suggestion_within_daily_cap_and_logs(): void
    {
        Queue::fake();
        $date = $this->fullNight();
        $this->enable(); // cap default 15 → +30% suggestion clamped to 115

        $this->artisan('pricing:autopilot')->assertSuccessful();

        $override = RateOverride::whereDate('date', $date)->where('room_type_id', $this->type->id)->first();
        $this->assertNotNull($override);
        $this->assertEquals(115.0, (float) $override->price, 'daily cap ±15% clamps the +30% move');
        $this->assertNull($override->created_by, 'autopilot writes are system-attributed');

        $log = $this->logFor($date);
        $this->assertNotNull($log);
        $this->assertNull($log->old_price, 'no override existed before');
        $this->assertEquals(100.0, (float) $log->effective_old_price, 'the effective seasonal/base price is retained as the daily baseline');
        $this->assertEquals(115.0, (float) $log->new_price);
        // Near-term empty nights legitimately get capped discounts too.
        $this->assertGreaterThan(1, PricingAutopilotLog::count());

        Queue::assertPushed(PushRoomTypeAri::class);
    }

    public function test_repeated_run_same_day_never_compounds_past_the_daily_cap(): void
    {
        $date = $this->fullNight();
        $this->enable(['daily_cap_pct' => '15']);

        $this->artisan('pricing:autopilot')->assertSuccessful();
        $this->artisan('pricing:autopilot')->assertSuccessful();

        $override = RateOverride::whereDate('date', $date)->where('room_type_id', $this->type->id)->firstOrFail();
        $this->assertEquals(115.0, (float) $override->price, 'the second run stays capped from the first price of the day');
        $this->assertSame(1, PricingAutopilotLog::whereDate('date', $date)->where('room_type_id', $this->type->id)->count());
    }

    public function test_duplicate_command_invocation_is_skipped_while_run_lock_is_held(): void
    {
        $this->fullNight();
        $this->enable();
        $lock = Cache::lock(TenantKey::make('pricing:autopilot:run'), 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('pricing:autopilot')->assertSuccessful();
        } finally {
            $lock->release();
        }

        $this->assertSame(0, RateOverride::count());
        $this->assertSame(0, PricingAutopilotLog::count());
    }

    public function test_a_later_date_failure_rolls_back_the_entire_room_type_batch(): void
    {
        $this->enable();
        $failDate = Carbon::tomorrow()->addDay()->toDateString();
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER fail_second_autopilot_write
                BEFORE INSERT ON rate_overrides FOR EACH ROW
                BEGIN
                    IF DATE(NEW.date) = '{$failDate}' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced batch failure';
                    END IF;
                END");
        } else {
            DB::unprepared("CREATE TRIGGER fail_second_autopilot_write
                BEFORE INSERT ON rate_overrides
                WHEN date(NEW.date) = '{$failDate}'
                BEGIN SELECT RAISE(ABORT, 'forced batch failure'); END;");
        }

        $failed = false;
        try {
            $exitCode = $this->artisan('pricing:autopilot', ['--days' => 3])->run();
            $failed = $exitCode !== 0;
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'the injected database failure must surface');
        $this->assertSame(0, RateOverride::count(), 'earlier dates roll back with the failed date');
        $this->assertSame(0, PricingAutopilotLog::count(), 'no partial audit batch remains');
    }

    public function test_command_refuses_to_run_when_an_active_type_has_missing_bounds(): void
    {
        $this->fullNight();
        $unbounded = RoomType::create(['name' => 'Unbounded', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);
        Room::create(['room_number' => 'UB1', 'room_type_id' => $unbounded->id, 'floor' => 1, 'status' => 'available']);
        $this->enable();

        $this->artisan('pricing:autopilot')->assertFailed();

        $this->assertSame(0, RateOverride::count());
        $this->assertSame(0, PricingAutopilotLog::count());
    }

    public function test_command_refuses_legacy_zero_or_inverted_bounds(): void
    {
        $this->fullNight();
        $this->type->update(['min_price' => 50, 'max_price' => 0]);
        $this->enable();

        $this->artisan('pricing:autopilot')->assertFailed();

        $this->assertSame(0, RateOverride::count());
        $this->assertSame(0, PricingAutopilotLog::count());
    }

    public function test_existing_out_of_bounds_price_is_never_auto_repaired_past_the_daily_cap(): void
    {
        $date = $this->fullNight();
        $this->type->update(['min_price' => 90, 'max_price' => 110]);
        RateOverride::create([
            'date' => $date,
            'room_type_id' => $this->type->id,
            'price' => 200,
            'created_by' => null,
        ]);
        $this->enable();

        $this->artisan('pricing:autopilot')->assertSuccessful();

        $this->assertEquals(200.0, (float) RateOverride::whereDate('date', $date)->first()->price);
        $this->assertNull($this->logFor($date));
    }

    public function test_materiality_guard_skips_small_changes(): void
    {
        $this->fullNight();
        $this->enable(['materiality_pct' => '40']); // +30% (pre-cap) is below 40 after cap → skip

        $this->artisan('pricing:autopilot')->assertSuccessful();
        $this->assertSame(0, RateOverride::count());
    }

    public function test_pause_window_guard_skips_dates_inside_it(): void
    {
        $date = $this->fullNight();
        $this->enable([
            'pause_from' => Carbon::parse($date)->subDay()->toDateString(),
            'pause_to' => Carbon::parse($date)->addDay()->toDateString(),
        ]);

        $this->artisan('pricing:autopilot')->assertSuccessful();
        $this->assertNull($this->logFor($date), 'peak-season pause wins for the paused date');
    }

    public function test_recent_manual_override_is_never_touched(): void
    {
        $date = $this->fullNight();
        $owner = User::factory()->create();
        RateOverride::create(['date' => $date, 'room_type_id' => $this->type->id, 'price' => 90, 'created_by' => $owner->id]);
        $this->enable();

        $this->artisan('pricing:autopilot')->assertSuccessful();

        $this->assertEquals(90.0, (float) RateOverride::whereDate('date', $date)->first()->price, "the owner's hand wins");
        $this->assertNull($this->logFor($date));
    }

    public function test_old_manual_override_may_be_repriced(): void
    {
        $date = $this->fullNight();
        $owner = User::factory()->create();
        $stale = RateOverride::create(['date' => $date, 'room_type_id' => $this->type->id, 'price' => 90, 'created_by' => $owner->id]);
        $stale->timestamps = false;
        $stale->updated_at = now()->subDays(10);
        $stale->save();
        $this->enable(['protect_manual_days' => '3']);

        $this->artisan('pricing:autopilot')->assertSuccessful();

        $log = $this->logFor($date);
        $this->assertNotNull($log, 'a 10-day-old manual price is fair game');
        $this->assertEquals(90.0, (float) $log->old_price);
    }

    public function test_revert_restores_previous_state_and_repushes(): void
    {
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $date = $this->fullNight();
        $this->enable();
        $this->artisan('pricing:autopilot')->assertSuccessful();
        $log = $this->logFor($date);

        $this->actingAs($admin)->post(route('pricing.smart.autopilot.revert', $log->id))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertNull(RateOverride::whereDate('date', $date)->first(), 'the date returns to the real seasonal fallback');
        $protection = PricingManualProtection::whereDate('date', $date)->firstOrFail();
        $this->assertSame($admin->id, $protection->created_by, 'the owner\'s revert is protected without a fake override');
        $this->assertNotNull($log->fresh()->reverted_at);
        Queue::assertPushed(PushRoomTypeAri::class);

        $logsBeforeRetry = PricingAutopilotLog::whereDate('date', $date)->count();
        $this->artisan('pricing:autopilot')->assertSuccessful();
        $this->assertSame($logsBeforeRetry, PricingAutopilotLog::whereDate('date', $date)->count());
        $this->assertNull(RateOverride::whereDate('date', $date)->first(), 'autopilot cannot immediately undo the owner\'s revert');

        // Second revert is rejected.
        $this->actingAs($admin)->post(route('pricing.smart.autopilot.revert', $log->id))
            ->assertSessionHas('error');
    }

    public function test_manual_remove_keeps_the_normal_rate_and_blocks_immediate_reapply(): void
    {
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $date = $this->fullNight();
        $this->enable();
        $this->artisan('pricing:autopilot')->assertSuccessful();
        $logsBeforeRemove = PricingAutopilotLog::whereDate('date', $date)->count();

        $this->actingAs($admin)->post(route('pricing.smart.remove'), [
            'date' => $date,
            'room_type_id' => $this->type->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertNull(RateOverride::whereDate('date', $date)->first());
        $this->assertSame($admin->id, PricingManualProtection::whereDate('date', $date)->firstOrFail()->created_by);

        $this->artisan('pricing:autopilot')->assertSuccessful();
        $this->assertNull(RateOverride::whereDate('date', $date)->first());
        $this->assertSame($logsBeforeRemove, PricingAutopilotLog::whereDate('date', $date)->count());
    }

    public function test_revert_does_not_overwrite_a_newer_manual_price(): void
    {
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $date = $this->fullNight();
        $this->enable();
        $this->artisan('pricing:autopilot')->assertSuccessful();
        $log = $this->logFor($date);

        $override = RateOverride::whereDate('date', $date)->firstOrFail();
        $override->update(['price' => 123, 'created_by' => $admin->id]);
        Queue::fake();

        $this->actingAs($admin)->post(route('pricing.smart.autopilot.revert', $log->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(123.0, (float) $override->fresh()->price);
        $this->assertNull($log->fresh()->reverted_at);
        Queue::assertNothingPushed();
    }

    public function test_revert_rejects_an_older_log_even_when_the_newer_system_price_matches(): void
    {
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $date = $this->fullNight();
        $this->enable();
        $this->artisan('pricing:autopilot')->assertSuccessful();
        $oldLog = $this->logFor($date);
        PricingAutopilotLog::create([
            'room_type_id' => $this->type->id,
            'date' => $date,
            'old_price' => 115,
            'effective_old_price' => 115,
            'new_price' => 115,
        ]);
        Queue::fake();

        $this->actingAs($admin)->post(route('pricing.smart.autopilot.revert', $oldLog->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(115.0, (float) RateOverride::whereDate('date', $date)->firstOrFail()->price);
        $this->assertNull($oldLog->fresh()->reverted_at);
        Queue::assertNothingPushed();
    }

    public function test_revert_refuses_to_publish_a_price_outside_current_bounds(): void
    {
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $date = $this->fullNight();
        $this->enable();
        $this->artisan('pricing:autopilot')->assertSuccessful();
        $log = $this->logFor($date);
        $this->type->update(['min_price' => 110, 'max_price' => 200]);
        Queue::fake();

        $this->actingAs($admin)->post(route('pricing.smart.autopilot.revert', $log->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(115.0, (float) RateOverride::whereDate('date', $date)->firstOrFail()->price);
        $this->assertNull($log->fresh()->reverted_at);
        Queue::assertNothingPushed();
    }

    public function test_disabling_stops_all_auto_writes_immediately(): void
    {
        $this->fullNight();
        $this->enable();
        Setting::set('pricing.autopilot.enabled', '0');

        $this->artisan('pricing:autopilot')->assertSuccessful();
        $this->assertSame(0, PricingAutopilotLog::count());
    }

    public function test_settings_endpoint_validates_and_persists(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('pricing.smart.autopilot'), [
            'enabled' => true, 'materiality_pct' => 7, 'daily_cap_pct' => 10,
            'protect_manual_days' => 5, 'pause_from' => '2026-08-01', 'pause_to' => '2026-08-25',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('1', Setting::get('pricing.autopilot.enabled'));
        $this->assertSame('2026-08-25', Setting::get('pricing.autopilot.pause_to'));

        // Inverted pause window rejected; command is scheduled.
        $this->actingAs($admin)->post(route('pricing.smart.autopilot'), [
            'enabled' => true, 'materiality_pct' => 5, 'daily_cap_pct' => 15,
            'protect_manual_days' => 3, 'pause_from' => '2026-08-25', 'pause_to' => '2026-08-01',
        ])->assertSessionHasErrors('pause_to');

        $this->actingAs($admin)->post(route('pricing.smart.autopilot'), [
            'enabled' => true, 'materiality_pct' => 5, 'daily_cap_pct' => 15,
            'protect_manual_days' => 3, 'pause_from' => 'August 1 2026', 'pause_to' => 'August 25 2026',
        ])->assertSessionHasErrors(['pause_from', 'pause_to']);

        $this->artisan('schedule:list')->expectsOutputToContain('pricing:autopilot')->assertSuccessful();
    }

    public function test_settings_endpoint_cannot_enable_with_missing_bounds_on_an_active_type(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $unbounded = RoomType::create(['name' => 'No Bounds', 'base_price' => 80, 'max_occupancy' => 2, 'amenities' => []]);
        Room::create(['room_number' => 'NB1', 'room_type_id' => $unbounded->id, 'floor' => 1, 'status' => 'available']);

        $this->actingAs($admin)->post(route('pricing.smart.autopilot'), [
            'enabled' => true,
            'materiality_pct' => 5,
            'daily_cap_pct' => 15,
            'protect_manual_days' => 3,
            'pause_from' => null,
            'pause_to' => null,
        ])->assertSessionHasErrors('enabled');

        $this->assertNotSame('1', Setting::get('pricing.autopilot.enabled'));
    }
}
