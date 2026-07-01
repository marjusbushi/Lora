<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\PricingEvent;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomInventorySnapshot;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\PricingEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task #193 (Copa 2): the deterministic factor pipeline. Every price =
 * reference × occupancy × pace × lead-time × DOW × events, clamped to the
 * owner's min/max — same inputs, same output, full breakdown.
 */
class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    private function type(float $base, array $extra = []): RoomType
    {
        return RoomType::create(array_merge([
            'name' => 'T'.fake()->unique()->numerify('##'),
            'base_price' => $base, 'max_occupancy' => 2, 'amenities' => [],
        ], $extra));
    }

    private static int $roomSeq = 0;

    private function rooms(RoomType $type, int $n, string $status = 'available'): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = Room::create(['room_number' => 'R'.(++self::$roomSeq), 'room_type_id' => $type->id, 'floor' => 1, 'status' => $status]);
        }

        return $out;
    }

    private function book(Room $room, string $date, int $nights = 1): void
    {
        Reservation::create([
            'room_id' => $room->id,
            'guest_id' => Guest::create(['first_name' => 'G', 'last_name' => 'X'])->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => $date,
            'check_out_date' => Carbon::parse($date)->addDays($nights)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 100,
            'adults' => 1,
            'channel' => 'direct',
        ]);
    }

    /** First non-Fri/Sat date >= today+$days (isolates asserts from the DOW factor). */
    private function weekdayAfter(int $days): Carbon
    {
        $d = Carbon::today()->addDays($days);
        while (in_array((int) $d->dayOfWeekIso, [5, 6], true)) {
            $d->addDay();
        }

        return $d;
    }

    private function rowFor(RoomType $type, Carbon $date): array
    {
        return PricingEngine::forRange($type, $date->copy(), $date->copy())[$date->toDateString()];
    }

    public function test_same_inputs_produce_the_same_output_with_breakdown(): void
    {
        $type = $this->type(100);
        [$a, $b] = $this->rooms($type, 2);
        $date = $this->weekdayAfter(15);
        $this->book($a, $date->toDateString());
        $this->book($b, $date->toDateString());

        $first = $this->rowFor($type, $date);
        $second = $this->rowFor($type, $date);

        $this->assertSame($first, $second, 'deterministic: identical inputs → identical output');
        $this->assertNotEmpty($first['factors']);
        $this->assertSame('occupancy', $first['factors'][0]['key']);
        $this->assertEquals(130.0, $first['suggested_price']); // 100% → +30 (anchor preserved)
    }

    public function test_property_pooling_tames_a_tiny_full_type_in_an_empty_hotel(): void
    {
        $small = $this->type(100);
        [$only] = $this->rooms($small, 1);
        $big = $this->type(90);
        $this->rooms($big, 7); // 7 empty rooms elsewhere

        $date = $this->weekdayAfter(15);
        $this->book($only, $date->toDateString()); // type 1/1 = 100%, property 1/8 = 12.5%

        $row = $this->rowFor($small, $date);
        // Blended (56%) sits in the neutral zone → no +30 knee-jerk raise.
        $this->assertEquals(0.0, $row['adjustment_pct']);
        $this->assertFalse($row['actionable']);
    }

    public function test_pace_factor_reacts_to_booking_velocity_from_snapshots(): void
    {
        $type = $this->type(100);
        $rooms = $this->rooms($type, 4);
        $date = $this->weekdayAfter(15);

        // 7 days ago the night had 0 on the books; today it has 3 → pickup +3/week.
        RoomInventorySnapshot::create([
            'snapshot_date' => Carbon::today()->subDays(7)->toDateString(),
            'stay_date' => $date->toDateString(),
            'room_type_id' => $type->id,
            'total_rooms' => 4, 'out_of_order' => 0, 'booked' => 0, 'available' => 4,
        ]);
        foreach (array_slice($rooms, 0, 3) as $room) {
            $this->book($room, $date->toDateString());
        }

        $row = $this->rowFor($type, $date);
        $pace = collect($row['factors'])->firstWhere('key', 'pace');
        $this->assertNotNull($pace, 'pace factor must fire when snapshots show pickup');
        $this->assertEquals(10.0, $pace['pct']);

        // Without a usable baseline snapshot there is no pace signal (cold start).
        RoomInventorySnapshot::query()->delete();
        $row = $this->rowFor($type, $date);
        $this->assertNull(collect($row['factors'])->firstWhere('key', 'pace'));
    }

    public function test_lead_time_far_out_hold_when_filling(): void
    {
        $type = $this->type(100);
        $rooms = $this->rooms($type, 4);
        $date = $this->weekdayAfter(65); // >= 60 days out
        foreach (array_slice($rooms, 0, 3) as $room) {
            $this->book($room, $date->toDateString()); // 75% → hot
        }

        $row = $this->rowFor($type, $date);
        $hold = collect($row['factors'])->firstWhere('key', 'lead_time');
        $this->assertNotNull($hold);
        $this->assertEquals(5.0, $hold['pct'], 'far out + filling → hold premium');
    }

    public function test_weekend_nights_carry_a_dow_premium(): void
    {
        $type = $this->type(100);
        [$a, $b] = $this->rooms($type, 2);

        // Next Friday at least 15 days out (clear of lead-time tiers).
        $friday = Carbon::today()->addDays(15);
        while ((int) $friday->dayOfWeekIso !== 5) {
            $friday->addDay();
        }
        $this->book($a, $friday->toDateString());
        $this->book($b, $friday->toDateString());

        $row = $this->rowFor($type, $friday);
        $dow = collect($row['factors'])->firstWhere('key', 'dow');
        $this->assertNotNull($dow);
        $this->assertEquals(8.0, $dow['pct']);
        $this->assertEquals(round(100 * 1.30 * 1.08, 2), $row['suggested_price']);
    }

    public function test_event_uplift_applies_and_is_never_scaled_by_strategy(): void
    {
        $type = $this->type(100);
        [$a, $b] = $this->rooms($type, 2);
        $date = $this->weekdayAfter(15);
        $this->book($a, $date->toDateString());
        $this->book($b, $date->toDateString()); // 100% → +30 base factor

        PricingEvent::create([
            'name' => 'Festa e Sarandës', 'date_from' => $date->toDateString(),
            'date_to' => $date->toDateString(), 'uplift_pct' => 10, 'source' => 'manual',
        ]);

        Setting::set('pricing.strategy', 'kujdesshem'); // scales demand factors ×0.6
        $row = $this->rowFor($type, $date);

        $occ = collect($row['factors'])->firstWhere('key', 'occupancy');
        $event = collect($row['factors'])->firstWhere('key', 'event');
        $this->assertEquals(18.0, $occ['pct'], 'occupancy scaled by strategy (30 × 0.6)');
        $this->assertEquals(10.0, $event['pct'], "owner's event uplift is NEVER scaled");
    }

    public function test_strategy_presets_change_intensity_via_one_setting(): void
    {
        $type = $this->type(100);
        [$a, $b] = $this->rooms($type, 2);
        $date = $this->weekdayAfter(15);
        $this->book($a, $date->toDateString());
        $this->book($b, $date->toDateString());

        $bySetting = [];
        foreach (['kujdesshem' => 118.0, 'balancuar' => 130.0, 'agresiv' => 142.0] as $strategy => $expected) {
            Setting::set('pricing.strategy', $strategy);
            $bySetting[$strategy] = $this->rowFor($type, $date)['suggested_price'];
            $this->assertEquals($expected, $bySetting[$strategy], "strategy {$strategy}");
        }
    }

    public function test_suggestions_clamp_to_owner_min_max_and_mark_it(): void
    {
        // Max clamp: full house would suggest 130, owner caps at 110.
        $capped = $this->type(100, ['max_price' => 110]);
        [$a, $b] = $this->rooms($capped, 2);
        $date = $this->weekdayAfter(15);
        $this->book($a, $date->toDateString());
        $this->book($b, $date->toDateString());

        $row = $this->rowFor($capped, $date);
        $this->assertEquals(110.0, $row['suggested_price']);
        $this->assertSame('max', $row['clamped']);

        // Min clamp: empty + last-minute would suggest ~81, owner floors at 95.
        $floored = $this->type(100, ['min_price' => 95]);
        $floorRooms = $this->rooms($floored, 5);
        $near = $this->weekdayAfter(1);
        $this->book($floorRooms[0], $near->toDateString()); // small demand so it stays actionable

        $row = $this->rowFor($floored, $near);
        if ($row['actionable']) {
            $this->assertGreaterThanOrEqual(95.0, $row['suggested_price']);
            $this->assertSame('min', $row['clamped']);
        } else {
            $this->assertSame(0.0, $row['adjustment_pct']);
        }
    }

    public function test_occupancy_never_exceeds_100_with_maintenance_room_reservations(): void
    {
        $type = $this->type(100);
        [$ok] = $this->rooms($type, 1);
        [$broken] = $this->rooms($type, 1, 'maintenance');
        $date = $this->weekdayAfter(15);
        $this->book($ok, $date->toDateString());
        $this->book($broken, $date->toDateString()); // reservation stuck on a maintenance room

        $row = $this->rowFor($type, $date);
        $this->assertLessThanOrEqual(100, $row['occupancy_pct']);
        $this->assertLessThanOrEqual($row['total'], $row['booked'], 'booked can never exceed sellable supply');
        $this->assertSame(1, $row['total'], 'maintenance room is not sellable supply');
    }

    public function test_far_future_empty_days_get_no_discount_nag(): void
    {
        $type = $this->type(100);
        $this->rooms($type, 3); // empty hotel

        $far = $this->weekdayAfter(30);
        $row = $this->rowFor($type, $far);
        $this->assertFalse($row['actionable']);
        $this->assertEquals($row['current_price'], $row['suggested_price']);
        $this->assertEmpty($row['factors'], 'suppressed demand factors must leave the breakdown too');
    }

    /** Review fix: a booked room flipping to maintenance must NOT read as demand cooling. */
    public function test_room_entering_maintenance_does_not_fake_negative_pace(): void
    {
        $type = $this->type(100);
        $rooms = $this->rooms($type, 6);
        $date = $this->weekdayAfter(10);

        foreach (array_slice($rooms, 0, 3) as $room) {
            $this->book($room, $date->toDateString());
        }
        RoomInventorySnapshot::create([
            'snapshot_date' => Carbon::today()->subDays(3)->toDateString(),
            'stay_date' => $date->toDateString(),
            'room_type_id' => $type->id,
            'total_rooms' => 6, 'out_of_order' => 0, 'booked' => 3, 'available' => 3,
        ]);

        // AC breaks in one of the booked rooms — the reservation stays.
        $rooms[0]->update(['status' => 'maintenance']);

        $row = $this->rowFor($type, $date);
        $this->assertNull(collect($row['factors'])->firstWhere('key', 'pace'),
            'zero cancellations happened — no pace factor may fire');
        $this->assertFalse($row['actionable']);
    }

    /** Review fix: the HTTP calendar path must clamp (partial model regression). */
    public function test_calendar_http_path_applies_the_max_clamp(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $type = $this->type(100, ['max_price' => 110]);
        [$a, $b] = $this->rooms($type, 2);
        $date = $this->weekdayAfter(15);
        $this->book($a, $date->toDateString());
        $this->book($b, $date->toDateString()); // would suggest 130 unclamped

        $response = $this->actingAs($admin)->get(route('pricing.smart.index', [
            'room_type_id' => $type->id, 'month' => $date->format('Y-m'),
        ]))->assertOk();

        $day = collect($response->viewData('page')['props']['days'])->firstWhere('date', $date->toDateString());
        $this->assertSame('max', $day['clamped']);
        $this->assertEquals(110.0, $day['suggested_price']);
    }

    /** Review fix: inverted min>max is treated as unset by BOTH engine and apply guard. */
    public function test_inverted_min_max_falls_back_consistently(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $type = $this->type(100, ['min_price' => 120, 'max_price' => 110]); // misconfigured
        [$a, $b] = $this->rooms($type, 2);
        $date = $this->weekdayAfter(15);
        $this->book($a, $date->toDateString());
        $this->book($b, $date->toDateString());

        $row = $this->rowFor($type, $date);
        $this->assertEquals(130.0, $row['suggested_price'], 'inverted pair = unset → no owner clamp');
        $this->assertNull($row['clamped']);

        // The guard accepts the engine's own suggestion (no suggest-then-reject loop).
        $this->actingAs($admin)->post(route('pricing.smart.apply'), [
            'date' => $date->toDateString(), 'room_type_id' => $type->id, 'price' => 130,
        ])->assertRedirect()->assertSessionMissing('error');
    }

    /** Review fix: the owner's explicit negative event uplift survives the far-future anti-nag. */
    public function test_negative_event_uplift_is_honored_far_out_and_breakdown_matches(): void
    {
        $type = $this->type(100);
        $this->rooms($type, 3); // empty → negative demand, which the anti-nag drops

        $far = $this->weekdayAfter(25);
        PricingEvent::create([
            'name' => 'Ulje speciale', 'date_from' => $far->toDateString(),
            'date_to' => $far->toDateString(), 'uplift_pct' => -20, 'source' => 'manual',
        ]);

        $row = $this->rowFor($type, $far);
        $this->assertTrue($row['actionable']);
        $this->assertEquals(80.0, $row['suggested_price'], 'owner intent is never suppressed');

        $product = collect($row['factors'])->reduce(fn ($p, $f) => $p * (1 + $f['pct'] / 100), 1.0);
        $this->assertEquals($row['suggested_price'], round($row['reference'] * $product, 2),
            'the breakdown must multiply out to the shown price');
    }

    /** Review fix: a far-future empty FRIDAY must not morph into a +8% raise. */
    public function test_far_future_empty_friday_gets_no_phantom_raise(): void
    {
        $type = $this->type(100);
        $this->rooms($type, 3); // empty hotel

        $friday = Carbon::today()->addDays(19);
        while ((int) $friday->dayOfWeekIso !== 5) {
            $friday->addDay();
        }

        $row = $this->rowFor($type, $friday);
        $this->assertFalse($row['actionable'], 'net-discount demand collapses entirely — DOW alone must not raise');
    }

    /** Review fix: the settings write path persists min/max and rejects an inverted pair. */
    public function test_settings_write_path_for_min_max(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = $this->type(100);

        $this->actingAs($admin)->put(route('settings.room-types.update', $type), [
            'name' => $type->name, 'base_price' => 100, 'max_occupancy' => 2,
            'min_price' => 70, 'max_price' => 150,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $type->refresh();
        $this->assertEquals(70.0, (float) $type->min_price);
        $this->assertEquals(150.0, (float) $type->max_price);

        $this->actingAs($admin)->put(route('settings.room-types.update', $type), [
            'name' => $type->name, 'base_price' => 100, 'max_occupancy' => 2,
            'min_price' => 150, 'max_price' => 70, // inverted
        ])->assertSessionHasErrors('max_price');
    }
}
