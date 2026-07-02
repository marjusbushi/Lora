<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use App\Models\User;
use App\Services\RoomPricing;
use App\Services\SmartPricing;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SmartPricingTest extends TestCase
{
    use RefreshDatabase;

    private function type(float $base = 80): RoomType
    {
        return RoomType::create(['name' => 'Deluxe', 'base_price' => $base, 'max_occupancy' => 2]);
    }

    private function augustSeason(RoomType $type, float $rate = 120): void
    {
        $season = Season::create(['name' => 'August', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'priority' => 10]);
        SeasonRate::create(['season_id' => $season->id, 'room_type_id' => $type->id, 'price' => $rate]);
    }

    public function test_date_override_beats_season_and_base(): void
    {
        $type = $this->type(80);
        $this->augustSeason($type, 120);
        RateOverride::create(['date' => '2026-08-15', 'room_type_id' => $type->id, 'price' => 150]);

        // Aug 14 → season 120; Aug 15 → override 150
        $q = RoomPricing::quote($type, '2026-08-14', '2026-08-16');

        $this->assertEquals(270.0, $q['total']); // 120 + 150
        $byDate = collect($q['breakdown'])->keyBy('date');
        $this->assertEquals(120.0, $byDate['2026-08-14']['price']);
        $this->assertEquals(150.0, $byDate['2026-08-15']['price']);
    }

    public function test_season_price_ignores_override(): void
    {
        $type = $this->type(80);
        $this->augustSeason($type, 120);
        RateOverride::create(['date' => '2026-08-15', 'room_type_id' => $type->id, 'price' => 150]);

        // seasonPrice returns the SEASON price (120), never the override
        $this->assertEquals(120.0, RoomPricing::seasonPrice($type, '2026-08-15'));
        // and falls back to base when no season covers the date
        $this->assertEquals(80.0, RoomPricing::seasonPrice($type, '2026-12-01'));
    }

    /** @return Room[] */
    private function rooms(RoomType $type, int $n): array
    {
        $rooms = [];
        for ($i = 1; $i <= $n; $i++) {
            $rooms[] = Room::create(['room_number' => $type->id.'0'.$i, 'room_type_id' => $type->id, 'floor' => 1, 'status' => 'available']);
        }

        return $rooms;
    }

    private function book(Room $room, string $date): void
    {
        Reservation::create([
            'room_id' => $room->id,
            'guest_id' => Guest::create(['first_name' => 'G', 'last_name' => 'X'])->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => $date,
            'check_out_date' => \Carbon\Carbon::parse($date)->addDay()->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 100,
            'adults' => 1,
        ]);
    }

    private function rowFor(array $rows, RoomType $type, string $date): ?array
    {
        return collect($rows)->first(fn ($r) => $r['date'] === $date && $r['room_type_id'] === $type->id);
    }

    /** First non-Fri/Sat date >= today+$days (the DOW factor would skew asserts). */
    private function weekdayAfter(int $days): string
    {
        $d = now()->startOfDay()->addDays($days);
        while (in_array((int) $d->dayOfWeekIso, [5, 6], true)) {
            $d->addDay();
        }

        return $d->toDateString();
    }

    public function test_full_occupancy_suggests_peak_increase(): void
    {
        $type = $this->type(100);
        [$a, $b] = $this->rooms($type, 2);
        $date = $this->weekdayAfter(15);
        $this->book($a, $date);
        $this->book($b, $date); // 2/2 = 100%

        $row = $this->rowFor(SmartPricing::suggestions(60), $type, $date);
        $this->assertNotNull($row);
        $this->assertEquals(100, $row['occupancy_pct']);
        $this->assertEquals(30.0, $row['adjustment_pct']);
        $this->assertEquals(130.0, $row['suggested_price']); // 100 × 1.30
    }

    public function test_low_occupancy_suggests_discount(): void
    {
        // Engine v2: continuous occupancy curve (20% → -7.5) + lead-time taper
        // (8-14 days out, soft → -4), composed multiplicatively.
        $type = $this->type(100);
        $rooms = $this->rooms($type, 5);
        $date = $this->weekdayAfter(9); // 9-11 days out — inside the discount horizon
        $this->book($rooms[0], $date); // 1/5 = 20%

        $row = $this->rowFor(SmartPricing::suggestions(60), $type, $date);
        $this->assertNotNull($row);
        $this->assertEquals(20, $row['occupancy_pct']);
        $this->assertEquals(-11.2, $row['adjustment_pct']); // 0.925 × 0.96 - 1
        $this->assertEquals(88.8, $row['suggested_price']);
        $keys = collect($row['factors'])->pluck('key');
        $this->assertTrue($keys->contains('occupancy'));
        $this->assertTrue($keys->contains('lead_time'));
    }

    public function test_last_minute_low_occupancy_discounts_more(): void
    {
        // Engine v2: ≤3 days out and soft → the deepest lead-time tier (-12),
        // on top of the occupancy curve (20% → -7.5): 100 × 0.925 × 0.88.
        $type = $this->type(100);
        $rooms = $this->rooms($type, 5);
        $date = $this->weekdayAfter(1); // 1-3 days out
        $this->book($rooms[0], $date); // 20%

        $row = $this->rowFor(SmartPricing::suggestions(60), $type, $date);
        $this->assertNotNull($row);
        $this->assertEquals(-18.6, $row['adjustment_pct']);
        $this->assertEquals(81.4, $row['suggested_price']);
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u;
    }

    public function test_apply_sets_a_date_price_and_remove_reverts(): void
    {
        $admin = $this->admin();
        $type = $this->type(100); // base 100, no season
        $date = now()->addDays(10)->toDateString();
        $next = now()->addDays(11)->toDateString();

        // Apply a suggested price of 150 for that single date
        $this->actingAs($admin)
            ->post(route('pricing.smart.apply'), ['date' => $date, 'room_type_id' => $type->id, 'price' => 150])
            ->assertRedirect();
        $this->assertEquals(150.0, RoomPricing::total($type, $date, $next)); // that night now 150

        // Re-applying the same date updates (no duplicate — unique date+type)
        $this->actingAs($admin)
            ->post(route('pricing.smart.apply'), ['date' => $date, 'room_type_id' => $type->id, 'price' => 140]);
        $this->assertEquals(1, RateOverride::where('room_type_id', $type->id)->count());

        // Remove → reverts to base
        $this->actingAs($admin)
            ->post(route('pricing.smart.remove'), ['date' => $date, 'room_type_id' => $type->id])
            ->assertRedirect();
        $this->assertEquals(100.0, RoomPricing::total($type, $date, $next)); // back to base
    }

    public function test_smart_pricing_page_renders(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('pricing.smart.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Pricing/Smart'));
    }

    public function test_calendar_marks_full_day_as_actionable_raise(): void
    {
        $type = $this->type(100);
        [$a] = $this->rooms($type, 1);
        $date = $this->weekdayAfter(4);
        $this->book($a, $date); // 1/1 = 100%

        $days = collect(SmartPricing::calendar($type, now()->startOfDay(), now()->addDays(20)->startOfDay()));
        $row = $days->firstWhere('date', $date);

        $this->assertTrue($row['actionable']);
        $this->assertSame('peak', $row['kind']);
        $this->assertEquals(130.0, $row['suggested_price']); // 100 × 1.30
    }

    public function test_calendar_suppresses_far_future_discounts(): void
    {
        $type = $this->type(100);
        $this->rooms($type, 2); // nothing booked → 0% everywhere

        $days = collect(SmartPricing::calendar($type, now()->startOfDay(), now()->addDays(20)->startOfDay()));

        $near = $days->firstWhere('date', now()->addDays(3)->toDateString());
        $this->assertTrue($near['actionable']);        // near + empty → discount
        $this->assertSame('low', $near['kind']);

        $far = $days->firstWhere('date', now()->addDays(19)->toDateString());
        $this->assertFalse($far['actionable']);        // far + empty → no nag
        $this->assertNull($far['kind']);
    }

    public function test_apply_rejects_absurd_single_price(): void
    {
        $admin = $this->admin();
        $type = $this->type(100);

        // €900,000 against a €100 base passes numeric validation but must be caught by the band guard.
        $this->actingAs($admin)->post(route('pricing.smart.apply'), [
            'date' => now()->addDays(5)->toDateString(), 'room_type_id' => $type->id, 'price' => 900000,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertEquals(0, RateOverride::count());
    }

    public function test_calendar_flags_weekends_and_holidays(): void
    {
        $type = $this->type(100);
        // Aug 2026: 14=Fri, 15=Sat (Ferragosto), 17=Mon.
        $days = collect(SmartPricing::calendar($type, \Carbon\Carbon::parse('2026-08-01'), \Carbon\Carbon::parse('2026-08-31')));

        $this->assertTrue($days->firstWhere('date', '2026-08-14')['is_weekend']);            // Friday night
        $this->assertTrue($days->firstWhere('date', '2026-08-15')['is_weekend']);            // Saturday night
        $this->assertStringContainsString('Ferragosto', $days->firstWhere('date', '2026-08-15')['holiday']);
        $this->assertFalse($days->firstWhere('date', '2026-08-17')['is_weekend']);           // Monday
        $this->assertNull($days->firstWhere('date', '2026-08-17')['holiday']);
    }
}
