<?php

namespace Tests\Feature;

use App\Jobs\PushRoomTypeAri;
use App\Models\Guest;
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
 * Task #194 (Copa 3): the calendar-v2 endpoints — strategy slider, per-type
 * bounds, and bulk apply-range where the SERVER recomputes every price.
 */
class PricingCalendarV2Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function fullType(float $base = 100): array
    {
        $type = RoomType::create(['name' => 'Twin', 'base_price' => $base, 'max_occupancy' => 2, 'amenities' => []]);
        $rooms = [];
        foreach ([1, 2] as $i) {
            $rooms[] = Room::create(['room_number' => 'C'.$i, 'room_type_id' => $type->id, 'floor' => 1, 'status' => 'available']);
        }

        return [$type, $rooms];
    }

    private function book(Room $room, string $date): void
    {
        Reservation::create([
            'room_id' => $room->id,
            'guest_id' => Guest::create(['first_name' => 'G', 'last_name' => 'X'])->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => $date,
            'check_out_date' => Carbon::parse($date)->addDay()->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 100,
            'adults' => 1,
            'channel' => 'direct',
        ]);
    }

    private function weekdayAfter(int $days): Carbon
    {
        $d = Carbon::today()->addDays($days);
        while (in_array((int) $d->dayOfWeekIso, [5, 6], true)) {
            $d->addDay();
        }

        return $d;
    }

    public function test_strategy_endpoint_persists_and_recomputes(): void
    {
        $admin = $this->admin();

        // A missing rules-version row previously surfaced as a misleading 404.
        Setting::where('group', 'pricing')->where('key', 'rules_version')->delete();

        $this->actingAs($admin)->post(route('pricing.smart.strategy'), ['strategy' => 'kujdesshem'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('kujdesshem', Setting::get('pricing.strategy'));
        $this->assertSame(1, (int) Setting::get('pricing.rules_version'));

        $this->actingAs($admin)->post(route('pricing.smart.strategy'), ['strategy' => 'agresiv'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('agresiv', Setting::get('pricing.strategy'));
        $this->assertSame(2, (int) Setting::get('pricing.rules_version'));

        $this->actingAs($admin)->post(route('pricing.smart.strategy'), ['strategy' => 'cowboy'])
            ->assertSessionHasErrors('strategy');
    }

    public function test_bounds_endpoint_persists_and_rejects_inverted(): void
    {
        $admin = $this->admin();
        [$type] = $this->fullType();

        $this->actingAs($admin)->put(route('pricing.smart.bounds', $type), ['min_price' => 60, 'max_price' => 180])
            ->assertRedirect()->assertSessionHasNoErrors();
        $type->refresh();
        $this->assertEquals(60.0, (float) $type->min_price);
        $this->assertEquals(180.0, (float) $type->max_price);

        $this->actingAs($admin)->put(route('pricing.smart.bounds', $type), ['min_price' => 200, 'max_price' => 100])
            ->assertSessionHasErrors('max_price');

        // Clearing both works (empty = no bound).
        $this->actingAs($admin)->put(route('pricing.smart.bounds', $type), ['min_price' => null, 'max_price' => null])
            ->assertRedirect()->assertSessionHasNoErrors();
        $type->refresh();
        $this->assertNull($type->min_price);
        $this->assertNull($type->max_price);
    }

    public function test_apply_range_writes_server_computed_actionable_prices_and_pushes(): void
    {
        Queue::fake();
        $admin = $this->admin();
        [$type, $rooms] = $this->fullType(100);

        // Two fully-booked weekday nights inside the window → two raise suggestions.
        $d1 = $this->weekdayAfter(15);
        $d2 = $this->weekdayAfter(20);
        foreach ([$d1, $d2] as $d) {
            $this->book($rooms[0], $d->toDateString());
            $this->book($rooms[1], $d->toDateString());
        }

        $this->actingAs($admin)->post(route('pricing.smart.apply-range'), [
            'room_type_id' => $type->id,
            'date_from' => Carbon::today()->addDays(10)->toDateString(),
            'date_to' => Carbon::today()->addDays(30)->toDateString(),
        ])->assertRedirect()->assertSessionHas('success');

        $overrides = RateOverride::where('room_type_id', $type->id)->get();
        $this->assertGreaterThanOrEqual(2, $overrides->count());
        foreach ([$d1, $d2] as $d) {
            $o = $overrides->first(fn ($x) => $x->date->toDateString() === $d->toDateString());
            $this->assertNotNull($o, "override for {$d->toDateString()}");
            $this->assertEquals(130.0, (float) $o->price, 'server-computed 100% occupancy price');
        }
        Queue::assertPushed(PushRoomTypeAri::class);
    }

    public function test_apply_range_rejects_long_ranges_and_reports_empty(): void
    {
        $admin = $this->admin();
        [$type] = $this->fullType();

        $this->actingAs($admin)->post(route('pricing.smart.apply-range'), [
            'room_type_id' => $type->id,
            'date_from' => Carbon::today()->toDateString(),
            'date_to' => Carbon::today()->addDays(60)->toDateString(),
        ])->assertSessionHas('error');

        // Empty hotel far-future window → nothing actionable → friendly error, no writes.
        $this->actingAs($admin)->post(route('pricing.smart.apply-range'), [
            'room_type_id' => $type->id,
            'date_from' => Carbon::today()->addDays(20)->toDateString(),
            'date_to' => Carbon::today()->addDays(25)->toDateString(),
        ])->assertSessionHas('error');
        $this->assertSame(0, RateOverride::count());
    }

    public function test_page_renders_with_v2_props(): void
    {
        $admin = $this->admin();
        $this->fullType();

        $response = $this->actingAs($admin)->get(route('pricing.smart.index'))->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('strategy', $props);
        $this->assertArrayHasKey('lastSyncAt', $props);
        $this->assertArrayHasKey('min_price', $props['roomTypes'][0]);
        $this->assertArrayHasKey('factors', $props['days'][0]);
    }
}
