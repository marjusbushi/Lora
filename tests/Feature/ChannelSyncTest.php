<?php

namespace Tests\Feature;

use App\Jobs\PushRoomTypeAri;
use App\Models\ChannelMapping;
use App\Models\ChannelSyncLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Season;
use App\Models\SeasonRate;
use App\Models\User;
use App\Services\ChannelSync;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChannelSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze "now" so the observer's today-floor (and the hardcoded-date
        // dispatch tests) are deterministic regardless of the wall clock.
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

    private function type(string $name = 'Std', float $base = 80, int $occ = 2): RoomType
    {
        return RoomType::create(['name' => $name, 'base_price' => $base, 'max_occupancy' => $occ, 'amenities' => []]);
    }

    private function rooms(RoomType $type, int $n, string $status = 'available'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Room::create(['room_type_id' => $type->id, 'room_number' => "{$type->id}-{$i}-{$status}", 'floor' => 1, 'status' => $status]);
        }
    }

    private function reservation(RoomType $type, string $in, string $out, string $status = 'confirmed'): Reservation
    {
        $room = Room::where('room_type_id', $type->id)->where('status', '!=', 'maintenance')->firstOrFail();
        $guest = Guest::create(['first_name' => 'A', 'last_name' => 'B', 'email' => uniqid().'@t.local', 'phone' => '1']);

        return Reservation::create([
            'room_id' => $room->id,
            'guest_id' => $guest->id,
            'created_by' => User::factory()->create()->id,
            'check_in_date' => $in,
            'check_out_date' => $out,
            'status' => $status,
        ]);
    }

    private function map(RoomType $type, string $rt = 'RT', string $rp = 'RP'): void
    {
        ChannelMapping::create([
            'channel' => 'channex',
            'room_type_id' => $type->id,
            'channex_property_id' => 'PROP-1',
            'channex_room_type_id' => $rt,
            'channex_rate_plan_id' => $rp,
        ]);
    }

    public function test_availability_subtracts_active_reservations(): void
    {
        $type = $this->type();
        $this->rooms($type, 3);
        $this->reservation($type, '2026-07-10', '2026-07-12'); // occupies 07-10, 07-11

        $avail = app(ChannelSync::class)->availabilityByDate(
            $type, CarbonImmutable::parse('2026-07-09'), CarbonImmutable::parse('2026-07-13')
        );

        $this->assertSame(3, $avail['2026-07-09']);
        $this->assertSame(2, $avail['2026-07-10']);
        $this->assertSame(2, $avail['2026-07-11']);
        $this->assertSame(3, $avail['2026-07-12']); // check-out day is free
        $this->assertSame(3, $avail['2026-07-13']);
    }

    public function test_availability_excludes_maintenance_and_floors_at_zero(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);                  // 1 sellable
        $this->rooms($type, 1, 'maintenance');   // excluded from supply
        $this->reservation($type, '2026-07-10', '2026-07-11');

        $avail = app(ChannelSync::class)->availabilityByDate(
            $type, CarbonImmutable::parse('2026-07-10'), CarbonImmutable::parse('2026-07-10')
        );
        $this->assertSame(0, $avail['2026-07-10']);
    }

    public function test_cancelled_reservation_does_not_reduce_availability(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $this->reservation($type, '2026-07-10', '2026-07-12', 'cancelled');

        $avail = app(ChannelSync::class)->availabilityByDate(
            $type, CarbonImmutable::parse('2026-07-10'), CarbonImmutable::parse('2026-07-10')
        );
        $this->assertSame(1, $avail['2026-07-10']);
    }

    public function test_price_by_date_applies_seasons(): void
    {
        $type = $this->type('Std', 80);
        $season = Season::create(['name' => 'High', 'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'priority' => 1]);
        SeasonRate::create(['season_id' => $season->id, 'room_type_id' => $type->id, 'price' => 110]);

        $prices = app(ChannelSync::class)->priceByDate(
            $type, CarbonImmutable::parse('2026-06-30'), CarbonImmutable::parse('2026-07-02')
        );
        $this->assertSame(80.0, $prices['2026-06-30']);
        $this->assertSame(110.0, $prices['2026-07-01']);
        $this->assertSame(110.0, $prices['2026-07-02']);
    }

    public function test_push_room_type_sends_consolidated_availability_and_rate_in_cents(): void
    {
        $type = $this->type('Std', 80);
        $this->rooms($type, 2);
        $this->map($type, 'RT-1', 'RP-1');
        Http::fake([
            '*availability*' => Http::response(['data' => []]),
            '*restrictions*' => Http::response(['data' => []]),
        ]);

        $ok = app(ChannelSync::class)->pushRoomType(
            $type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-03')
        );
        $this->assertTrue($ok);
        $this->assertSame(
            2,
            ChannelSyncLog::query()
                ->where('room_type_id', $type->id)
                ->whereIn('action', ['availability', 'rate'])
                ->where('status', 'ok')
                ->count(),
        );

        // flat 2 rooms, no reservations -> ONE availability range
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/availability')) {
                return false;
            }
            $v = $r->data()['values'];

            return count($v) === 1 && (int) $v[0]['availability'] === 2
                && $v[0]['date_from'] === '2026-07-01' && $v[0]['date_to'] === '2026-07-03'
                && $v[0]['room_type_id'] === 'RT-1';
        });
        // flat 80 EUR -> ONE rate range at 8000 cents
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/restrictions')) {
                return false;
            }
            $v = $r->data()['values'];

            return count($v) === 1 && (int) $v[0]['rate'] === 8000 && $v[0]['rate_plan_id'] === 'RP-1';
        });
    }

    public function test_push_room_type_consolidates_into_multiple_ranges_when_a_night_is_booked(): void
    {
        $type = $this->type('Std', 80);
        $this->rooms($type, 2);
        $this->map($type, 'RT-1', 'RP-1');
        $this->reservation($type, '2026-07-02', '2026-07-03'); // 07-02 occupied -> avail 1 that night
        Http::fake(['*availability*' => Http::response(['data' => []]), '*restrictions*' => Http::response(['data' => []])]);

        app(ChannelSync::class)->pushRoomType($type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-03'));

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/availability')) {
                return false;
            }
            $v = collect($r->data()['values'])->keyBy('date_from');

            return (int) $v['2026-07-01']['availability'] === 2   // 07-01 free
                && (int) $v['2026-07-02']['availability'] === 1   // 07-02 one booked
                && (int) $v['2026-07-03']['availability'] === 2;  // 07-03 free again
        });
    }

    public function test_push_room_type_skips_without_mapping(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $this->assertFalse(app(ChannelSync::class)->pushRoomType($type)); // preventStrayRequests => no HTTP
    }

    public function test_push_room_type_skips_when_not_configured(): void
    {
        config(['services.channex.api_key' => '']);
        $type = $this->type();
        $this->map($type);
        $this->assertFalse(app(ChannelSync::class)->pushRoomType($type));
    }

    public function test_reservation_save_dispatches_push_job_when_configured(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $this->reservation($type, '2026-07-10', '2026-07-12');

        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $type->id);
    }

    public function test_no_dispatch_when_channex_not_configured(): void
    {
        config(['services.channex.api_key' => '']);
        $type = $this->type();
        $this->rooms($type, 1);
        $this->reservation($type, '2026-07-10', '2026-07-12');

        Queue::assertNotPushed(PushRoomTypeAri::class);
    }

    public function test_dispatch_all_mapped_queues_each_mapped_type(): void
    {
        $a = $this->type('A');
        $this->map($a, 'RTA', 'RPA');
        $b = $this->type('B');
        $this->map($b, 'RTB', 'RPB');
        $this->type('C'); // not mapped

        $this->assertSame(2, PushRoomTypeAri::dispatchAllMapped());
        Queue::assertPushed(PushRoomTypeAri::class, 2);
    }

    public function test_job_pushes_the_room_type(): void
    {
        $type = $this->type('Std', 80);
        $this->rooms($type, 1);
        $this->map($type, 'RT-9', 'RP-9');
        Http::fake(['*availability*' => Http::response(['data' => []]), '*restrictions*' => Http::response(['data' => []])]);

        (new PushRoomTypeAri($type->id))->handle(app(ChannelSync::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/availability'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/restrictions'));
    }

    public function test_sync_now_route_dispatches_for_mapped_types(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = $this->type();
        $this->map($type, 'RT-1', 'RP-1');

        $this->actingAs($admin)->post(route('channex.sync'))->assertRedirect();

        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $type->id);
    }

    public function test_push_throws_on_a_rejected_push_so_the_job_retries(): void
    {
        $type = $this->type('Std', 80);
        $this->rooms($type, 1);
        $this->map($type, 'RT-1', 'RP-1');
        Http::fake([
            '*availability*' => Http::response(['data' => []]),
            '*restrictions*' => Http::response(['errors' => ['bad']], 422), // Channex rejects the rate
        ]);

        $this->expectException(\RuntimeException::class);
        app(ChannelSync::class)->pushRoomType($type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-02'));
    }

    public function test_push_throws_when_channex_returns_http_200_with_ari_warnings(): void
    {
        $type = $this->type('Std', 80);
        $this->rooms($type, 1);
        $this->map($type, 'RT-1', 'RP-1');
        Http::fake([
            '*availability*' => Http::response(['data' => [], 'meta' => ['warnings' => []]]),
            '*restrictions*' => Http::response([
                'data' => [],
                'meta' => ['warnings' => [['warning' => ['rate' => ['rejected']]]]],
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        app(ChannelSync::class)->pushRoomType(
            $type,
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-02'),
        );
    }

    public function test_checked_out_reservation_does_not_reduce_availability(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $this->reservation($type, '2026-07-10', '2026-07-12', 'checked_out');

        $avail = app(ChannelSync::class)->availabilityByDate($type, CarbonImmutable::parse('2026-07-10'), CarbonImmutable::parse('2026-07-10'));
        $this->assertSame(1, $avail['2026-07-10']);
    }

    public function test_reservation_outside_the_window_does_not_affect_availability(): void
    {
        $type = $this->type();
        $this->rooms($type, 2);
        $this->reservation($type, '2026-09-01', '2026-09-03'); // far outside the queried window

        $avail = app(ChannelSync::class)->availabilityByDate($type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-05'));
        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04', '2026-07-05'] as $d) {
            $this->assertSame(2, $avail[$d]);
        }
    }

    public function test_check_in_on_the_window_end_is_counted_where_date_boundary(): void
    {
        // Guards the whereDate fix: a raw '<=' against a 'YYYY-MM-DD 00:00:00' value
        // would drop this same-day check-in and report the room as free.
        $type = $this->type();
        $this->rooms($type, 1);
        $this->reservation($type, '2026-07-05', '2026-07-06'); // check-in == window end

        $avail = app(ChannelSync::class)->availabilityByDate($type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-05'));
        $this->assertSame(1, $avail['2026-07-01']);
        $this->assertSame(0, $avail['2026-07-05']); // occupied on the window-end day
    }

    public function test_check_out_on_the_window_start_does_not_occupy_it(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $this->reservation($type, '2026-06-29', '2026-07-01'); // checks out ON the window start

        $avail = app(ChannelSync::class)->availabilityByDate($type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-02'));
        $this->assertSame(1, $avail['2026-07-01']); // check-out day is free
    }

    public function test_consolidates_multi_day_runs_with_correct_inclusive_range_ends(): void
    {
        $type = $this->type('Std', 80);
        $this->rooms($type, 2);
        $this->map($type, 'RT-1', 'RP-1');
        $this->reservation($type, '2026-07-05', '2026-07-06'); // dips 07-05 to 1
        Http::fake(['*availability*' => Http::response(['data' => []]), '*restrictions*' => Http::response(['data' => []])]);

        app(ChannelSync::class)->pushRoomType($type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-10'));

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/availability')) {
                return false;
            }
            $ranges = collect($r->data()['values'])->map(fn ($v) => "{$v['date_from']}..{$v['date_to']}={$v['availability']}")->all();

            return $ranges === ['2026-07-01..2026-07-04=2', '2026-07-05..2026-07-05=1', '2026-07-06..2026-07-10=2'];
        });
    }

    public function test_reservation_update_re_dispatches_push(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $res = $this->reservation($type, '2026-07-10', '2026-07-12');

        Queue::fake(); // reset captures: ignore the create-time dispatch
        $res->update(['status' => 'cancelled']);

        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $type->id);
    }

    public function test_reservation_delete_re_dispatches_push(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $res = $this->reservation($type, '2026-07-10', '2026-07-12');

        Queue::fake();
        $res->delete();

        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $type->id);
    }

    public function test_moving_a_reservation_across_room_types_repushes_both(): void
    {
        $a = $this->type('A');
        $this->rooms($a, 1);
        $b = $this->type('B');
        $this->rooms($b, 1);
        $res = $this->reservation($a, '2026-07-10', '2026-07-12');
        $bRoom = Room::where('room_type_id', $b->id)->firstOrFail();

        Queue::fake();
        $res->update(['room_id' => $bRoom->id]); // move A -> B

        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $a->id); // old type freed
        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $b->id); // new type
    }

    public function test_partial_mapping_pushes_availability_but_not_rate(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        ChannelMapping::create([
            'channel' => 'channex',
            'room_type_id' => $type->id,
            'channex_property_id' => 'PROP-1',
            'channex_room_type_id' => 'RT-1',
            'channex_rate_plan_id' => null, // half mapping: no rate plan
        ]);
        Http::fake(['*availability*' => Http::response(['data' => []])]); // restrictions NOT faked -> would throw if called

        $ok = app(ChannelSync::class)->pushRoomType($type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-02'));

        $this->assertTrue($ok);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/availability'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/restrictions'));
    }

    public function test_pricing_save_dispatches_for_mapped_types(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = $this->type();
        $this->map($type, 'RT-1', 'RP-1');

        $this->actingAs($admin)->post(route('pricing.rates.save'), [
            'base' => [$type->id => 90],
            'rates' => [],
        ])->assertRedirect();

        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $type->id);
    }

    public function test_push_ari_command_fails_when_unconfigured(): void
    {
        config(['services.channex.api_key' => '']);
        $this->artisan('channex:push-ari')->assertFailed();
    }

    public function test_push_ari_command_warns_when_no_mappings(): void
    {
        $this->artisan('channex:push-ari')->expectsOutputToContain('No Channex-mapped')->assertSuccessful();
    }

    public function test_push_ari_command_queue_option_dispatches(): void
    {
        $type = $this->type();
        $this->map($type, 'RT-1', 'RP-1');

        $this->artisan('channex:push-ari', ['--queue' => true, '--days' => 30])->assertSuccessful();
        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $type->id
            && $job->from === '2026-07-01'
            && $job->to === '2026-07-31');
    }

    public function test_inline_push_ari_command_returns_failure_when_a_room_type_push_fails(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $this->map($type, 'RT-1', 'RP-1');
        Http::fake([
            '*availability*' => Http::response(['data' => []]),
            '*restrictions*' => Http::response(['errors' => ['rejected']], 422),
        ]);

        $this->artisan('channex:push-ari', ['--days' => 1])->assertFailed();
    }

    public function test_job_no_ops_for_a_missing_room_type(): void
    {
        (new PushRoomTypeAri(999999))->handle(app(ChannelSync::class)); // preventStrayRequests => no HTTP, no throw
        $this->assertTrue(true);
    }

    // -- incremental push: reservation events send ONLY the changed nights -----

    public function test_one_night_booking_dispatches_a_push_scoped_to_that_single_night(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $in = CarbonImmutable::today()->addDays(10);
        $this->reservation($type, $in->toDateString(), $in->addDay()->toDateString()); // 1 night: occupies $in only

        // NOT the full today..today+365 window — just the one booked night.
        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $type->id
            && $job->from === $in->toDateString()
            && $job->to === $in->toDateString());
    }

    public function test_job_with_a_window_pushes_only_that_range_to_channex(): void
    {
        $type = $this->type('Std', 80);
        $this->rooms($type, 2);
        $this->map($type, 'RT-1', 'RP-1');
        Http::fake(['*availability*' => Http::response(['data' => []]), '*restrictions*' => Http::response(['data' => []])]);

        (new PushRoomTypeAri($type->id, '2026-08-10', '2026-08-10'))->handle(app(ChannelSync::class));

        // A 1-night window => exactly one availability range spanning only that day.
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/availability')) {
                return false;
            }
            $v = $r->data()['values'];

            return count($v) === 1
                && $v[0]['date_from'] === '2026-08-10'
                && $v[0]['date_to'] === '2026-08-10';
        });
    }

    public function test_extending_a_reservation_pushes_the_union_of_old_and_new_nights(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $in = CarbonImmutable::today()->addDays(10);
        $res = $this->reservation($type, $in->toDateString(), $in->addDay()->toDateString()); // 1 night

        Queue::fake(); // ignore the create-time dispatch
        $res->update(['check_out_date' => $in->addDays(3)->toDateString()]); // now 3 nights: $in, +1, +2

        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $type->id
            && $job->from === $in->toDateString()
            && $job->to === $in->addDays(2)->toDateString()); // last occupied night = new check_out - 1
    }

    public function test_a_fully_past_reservation_change_dispatches_nothing(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $past = CarbonImmutable::today()->subDays(10);
        $this->reservation($type, $past->toDateString(), $past->addDay()->toDateString());

        Queue::assertNotPushed(PushRoomTypeAri::class); // past nights are unsellable — nothing to push
    }

    public function test_window_is_clamped_to_today_for_a_stay_that_started_in_the_past(): void
    {
        $type = $this->type();
        $this->rooms($type, 1);
        $in = CarbonImmutable::today()->subDays(2);
        $out = CarbonImmutable::today()->addDays(2); // occupies past AND future nights
        $this->reservation($type, $in->toDateString(), $out->toDateString());

        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $type->id
            && $job->from === CarbonImmutable::today()->toDateString()        // floored at today
            && $job->to === CarbonImmutable::today()->addDay()->toDateString()); // last night = check_out - 1
    }

    // -- OTA price parity: per-channel rate plans get compensated prices -------

    private function mapWithChannelPlans(RoomType $type): void
    {
        ChannelMapping::create([
            'channel' => 'channex',
            'room_type_id' => $type->id,
            'channex_property_id' => 'PROP-1',
            'channex_room_type_id' => 'RT-1',
            'channex_rate_plan_id' => 'RP-BASE',
            'channex_booking_rate_plan_id' => 'RP-BOOK',
            'channex_expedia_rate_plan_id' => 'RP-EXP',
        ]);
    }

    private function enablePrograms(): void
    {
        Setting::set('pricing_programs.booking_genius_enabled', '1', 'boolean');
        Setting::set('pricing_programs.booking_genius_pct', 15, 'number');
        Setting::set('pricing_programs.expedia_member_enabled', '1', 'boolean');
        Setting::set('pricing_programs.expedia_member_pct', 10, 'number');
    }

    /** cents pushed to one rate plan id, or null if that plan got no push */
    private function pushedCents(string $planId): ?int
    {
        foreach (Http::recorded() as [$req]) {
            if ($req->method() === 'POST' && str_contains($req->url(), '/restrictions')) {
                $v = $req->data()['values'][0] ?? [];
                if (($v['rate_plan_id'] ?? null) === $planId) {
                    return (int) $v['rate'];
                }
            }
        }

        return null;
    }

    public function test_push_sends_compensated_rates_to_channel_plans(): void
    {
        $type = $this->type('Std', 80);
        $this->rooms($type, 2);
        $this->mapWithChannelPlans($type);
        $this->enablePrograms(); // Genius 15% / Member 10%
        Http::fake(['*availability*' => Http::response(['data' => []]), '*restrictions*' => Http::response(['data' => []])]);

        $ok = app(ChannelSync::class)->pushRoomType($type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-03'));

        $this->assertTrue($ok);
        $this->assertSame(8000, $this->pushedCents('RP-BASE'), 'base plan keeps the canonical PMS price');
        $this->assertSame(9412, $this->pushedCents('RP-BOOK'), '80/0.85=94.12 so Genius -15% lands back on 80');
        $this->assertSame(8889, $this->pushedCents('RP-EXP'), '80/0.90=88.89 so Member -10% lands back on 80');
    }

    public function test_channel_plans_receive_base_price_when_no_programs_enabled(): void
    {
        $type = $this->type('Std', 80);
        $this->rooms($type, 2);
        $this->mapWithChannelPlans($type); // programs NOT enabled -> factor 1.0
        Http::fake(['*availability*' => Http::response(['data' => []]), '*restrictions*' => Http::response(['data' => []])]);

        app(ChannelSync::class)->pushRoomType($type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-02'));

        $this->assertSame(8000, $this->pushedCents('RP-BASE'));
        $this->assertSame(8000, $this->pushedCents('RP-BOOK'));
        $this->assertSame(8000, $this->pushedCents('RP-EXP'));
    }

    public function test_unmapped_channel_plans_keep_single_rate_push(): void
    {
        $type = $this->type('Std', 80);
        $this->rooms($type, 2);
        $this->map($type, 'RT-1', 'RP-BASE'); // legacy mapping: no channel plans
        $this->enablePrograms();
        Http::fake(['*availability*' => Http::response(['data' => []]), '*restrictions*' => Http::response(['data' => []])]);

        app(ChannelSync::class)->pushRoomType($type, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-02'));

        $rateCalls = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/restrictions'))
            ->count();
        $this->assertSame(1, $rateCalls, 'only the base plan is pushed when channel plans are unmapped');
        $this->assertSame(8000, $this->pushedCents('RP-BASE'));
    }

    public function test_saving_pricing_programs_redispatches_ari_push(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $type = $this->type();
        $this->map($type, 'RT-1', 'RP-1');

        $this->actingAs($admin)->put(route('settings.pricing-programs'), [
            'booking_genius_enabled' => true,
            'booking_genius_pct' => 15,
            'booking_mobile_enabled' => false,
            'booking_mobile_pct' => 10,
            'booking_preferred_enabled' => true,
            'expedia_member_enabled' => true,
            'expedia_member_pct' => 10,
            'expedia_mobile_enabled' => false,
            'expedia_mobile_pct' => 10,
        ])->assertRedirect();

        Queue::assertPushed(PushRoomTypeAri::class, fn ($job) => $job->roomTypeId === $type->id);
    }
}
