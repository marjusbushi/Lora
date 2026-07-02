<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\PricingAutopilotLog;
use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->type = RoomType::create(['name' => 'Twin', 'base_price' => 100, 'max_occupancy' => 2, 'amenities' => []]);
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
        $this->assertEquals(115.0, (float) $log->new_price);
        // Near-term empty nights legitimately get capped discounts too.
        $this->assertGreaterThan(1, PricingAutopilotLog::count());

        Queue::assertPushed(\App\Jobs\PushRoomTypeAri::class);
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

        $this->assertNull(RateOverride::whereDate('date', $date)->first(), 'old_price null → override removed (back to seasonal)');
        $this->assertNotNull($log->fresh()->reverted_at);
        Queue::assertPushed(\App\Jobs\PushRoomTypeAri::class);

        // Second revert is rejected.
        $this->actingAs($admin)->post(route('pricing.smart.autopilot.revert', $log->id))
            ->assertSessionHas('error');
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

        $this->artisan('schedule:list')->expectsOutputToContain('pricing:autopilot')->assertSuccessful();
    }
}
