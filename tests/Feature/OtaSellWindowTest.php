<?php

namespace Tests\Feature;

use App\Jobs\FinalizeOtaSellWindow;
use App\Jobs\PushRoomTypeAri;
use App\Jobs\ReconcileOtaRoomType;
use App\Jobs\ReconcileOtaSellWindow;
use App\Models\AuditLog;
use App\Models\ChannelMapping;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\ChannelSync;
use App\Services\ChannexClient;
use App\Services\OtaSellWindow;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OtaSellWindowTest extends TestCase
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

    public function test_absent_setting_preserves_the_legacy_inclusive_365_day_window(): void
    {
        $type = $this->mappedType();
        Http::fake([
            '*availability*' => Http::response(['data' => []]),
            '*restrictions*' => Http::response(['data' => []]),
        ]);

        $summary = app(OtaSellWindow::class)->summary();
        $this->assertNull($summary['configured_until']);
        $this->assertSame('2027-07-01', $summary['default_until']);
        $this->assertSame('2027-07-01', $summary['effective_until']);
        $this->assertSame('2026-07-01', $summary['min_date']);
        $this->assertSame('2027-11-12', $summary['max_date']);
        $this->assertSame(500, $summary['max_days']);

        app(ChannelSync::class)->pushRoomType($type);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/availability')
            && $request->data()['values'][0]['date_from'] === '2026-07-01'
            && $request->data()['values'][0]['date_to'] === '2027-07-01');
    }

    public function test_every_push_is_clamped_and_explicit_outside_nights_are_reclosed(): void
    {
        $type = $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-07-03');
        Http::fake([
            '*availability*' => Http::response(['data' => []]),
            '*restrictions*' => Http::response(['data' => []]),
        ]);

        app(ChannelSync::class)->pushRoomType(
            $type,
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-10'),
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/availability')
            && $request->data()['values'][0]['date_to'] === '2026-07-03');
        Http::assertSentCount(2);

        app(ChannelSync::class)->pushRoomType(
            $type,
            CarbonImmutable::parse('2026-07-04'),
            CarbonImmutable::parse('2026-07-05'),
        );
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/availability')
            && count($request->data()['values'] ?? []) === 1
            && $request->data()['values'][0]['date_from'] === '2026-07-04'
            && $request->data()['values'][0]['date_to'] === '2026-07-05'
            && $request->data()['values'][0]['availability'] === 0);
    }

    public function test_an_explicit_reservation_update_after_cutoff_is_reclosed_with_zero_availability(): void
    {
        $type = $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-07-03');
        Http::fake(['*availability*' => Http::response(['data' => [], 'meta' => ['warnings' => []]])]);

        app(ChannelSync::class)->pushRoomType(
            $type,
            CarbonImmutable::parse('2026-07-04'),
            CarbonImmutable::parse('2026-07-05'),
        );

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $values = $request->data()['values'] ?? [];

            return str_contains($request->url(), '/availability')
                && count($values) === 1
                && $values[0]['date_from'] === '2026-07-04'
                && $values[0]['date_to'] === '2026-07-05'
                && $values[0]['availability'] === 0;
        });
    }

    public function test_an_explicit_range_crossing_cutoff_pushes_truth_then_zero_tail(): void
    {
        $type = $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-07-03');
        Setting::set(OtaSellWindow::MAX_PUBLISHED_KEY, '2026-07-05');
        Http::fake([
            '*availability*' => Http::response(['data' => [], 'meta' => ['warnings' => []]]),
            '*restrictions*' => Http::response(['data' => [], 'meta' => ['warnings' => []]]),
        ]);

        app(ChannelSync::class)->pushRoomType(
            $type,
            CarbonImmutable::parse('2026-07-02'),
            CarbonImmutable::parse('2026-07-05'),
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/availability')) {
                return false;
            }
            $values = $request->data()['values'];

            return count($values) === 2
                && $values[0]['date_from'] === '2026-07-02'
                && $values[0]['date_to'] === '2026-07-03'
                && $values[0]['availability'] === 2
                && $values[1]['date_from'] === '2026-07-04'
                && $values[1]['date_to'] === '2026-07-05'
                && $values[1]['availability'] === 0;
        });
        Http::assertSent(fn ($request) => str_contains($request->url(), '/restrictions')
            && $request->data()['values'][0]['date_from'] === '2026-07-02'
            && $request->data()['values'][0]['date_to'] === '2026-07-03');
    }

    public function test_room_reconcile_combines_truth_and_closure_in_one_availability_call_without_stop_sell(): void
    {
        $type = $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-07-03');
        Setting::set(OtaSellWindow::VERSION_KEY, '1', 'number');
        Setting::set(OtaSellWindow::MAX_PUBLISHED_KEY, '2026-07-05');
        Http::fake([
            '*availability*' => Http::response(['data' => []]),
            '*restrictions*' => Http::response(['data' => []]),
        ]);

        (new ReconcileOtaRoomType($type->id, 1, '2026-07-03'))->handle(
            app(ChannelSync::class),
            app(OtaSellWindow::class),
        );

        Http::assertSentCount(2); // exactly one availability + one rate call
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/availability')) {
                return false;
            }
            $values = $request->data()['values'];

            return count($values) === 2
                && $values[0]['date_from'] === '2026-07-01'
                && $values[0]['date_to'] === '2026-07-03'
                && (int) $values[0]['availability'] === 2
                && $values[1]['date_from'] === '2026-07-04'
                && $values[1]['date_to'] === '2027-11-12'
                && (int) $values[1]['availability'] === 0
                && ! array_key_exists('stop_sell', $values[0])
                && ! array_key_exists('stop_sell', $values[1]);
        });
        Http::assertSent(fn ($request) => str_contains($request->url(), '/restrictions')
            && $request->data()['values'][0]['date_to'] === '2026-07-03'
            && ! array_key_exists('stop_sell', $request->data()['values'][0]));
        $this->assertNull(Setting::get(OtaSellWindow::APPLIED_UNTIL_KEY));
    }

    public function test_nightly_reconcile_closes_the_new_rolling_inventory_edge(): void
    {
        $type = $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-07-03');
        Setting::set(OtaSellWindow::VERSION_KEY, '1', 'number');
        Http::fake([
            '*availability*' => Http::response(['data' => []]),
            '*restrictions*' => Http::response(['data' => []]),
        ]);

        (new ReconcileOtaRoomType($type->id, 1, '2026-07-03'))->handle(
            app(ChannelSync::class),
            app(OtaSellWindow::class),
        );
        Http::assertSent(fn ($request) => str_contains($request->url(), '/availability')
            && collect($request->data()['values'] ?? [])->last()['date_to'] === '2027-11-12'
            && collect($request->data()['values'] ?? [])->last()['availability'] === 0);

        Carbon::setTestNow('2026-07-02 09:00:00');
        CarbonImmutable::setTestNow('2026-07-02 09:00:00');

        (new ReconcileOtaRoomType($type->id, 1, '2026-07-03'))->handle(
            app(ChannelSync::class),
            app(OtaSellWindow::class),
        );
        Http::assertSent(fn ($request) => str_contains($request->url(), '/availability')
            && collect($request->data()['values'] ?? [])->last()['date_to'] === '2027-11-13'
            && collect($request->data()['values'] ?? [])->last()['availability'] === 0);
    }

    public function test_a_stale_reconcile_revision_is_a_zero_http_no_op(): void
    {
        $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-07-10');
        Setting::set(OtaSellWindow::VERSION_KEY, '2', 'number');

        (new ReconcileOtaSellWindow(1, '2026-07-03'))->handle(app(OtaSellWindow::class));

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertNull(Setting::get(OtaSellWindow::APPLIED_UNTIL_KEY));
    }

    public function test_current_reconcile_revision_fans_out_into_short_per_room_jobs(): void
    {
        $type = $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-07-03');
        Setting::set(OtaSellWindow::VERSION_KEY, '1', 'number');

        (new ReconcileOtaSellWindow(1, '2026-07-03'))->handle(app(OtaSellWindow::class));

        Queue::assertPushedWithChain(
            ReconcileOtaRoomType::class,
            [FinalizeOtaSellWindow::class],
            fn (ReconcileOtaRoomType $job) => $job->roomTypeId === $type->id && $job->version === 1,
        );
        Http::assertNothingSent();
    }

    public function test_finalizer_reads_back_the_remote_zero_tail_before_marking_applied(): void
    {
        $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-07-03');
        Setting::set(OtaSellWindow::VERSION_KEY, '1', 'number');
        Setting::set(OtaSellWindow::MAX_PUBLISHED_KEY, '2026-07-05');
        Http::fake([
            '*availability*' => Http::response(['data' => [
                'RT-1' => $this->remoteAvailabilityThrough('2026-07-03'),
            ]]),
            '*restrictions*' => Http::response(['data' => [
                'RP-1' => [
                    '2026-07-01' => ['rate' => '80.00'],
                    '2026-07-02' => ['rate' => '80.00'],
                    '2026-07-03' => ['rate' => '80.00'],
                ],
            ]]),
        ]);

        (new FinalizeOtaSellWindow(1, '2026-07-03'))->handle(
            app(OtaSellWindow::class),
            app(ChannexClient::class),
            app(ChannelSync::class),
        );

        $this->assertSame('2026-07-03', Setting::get(OtaSellWindow::APPLIED_UNTIL_KEY));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/availability'));
    }

    public function test_finalizer_rejects_a_malformed_remote_zero_value(): void
    {
        $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-07-03');
        Setting::set(OtaSellWindow::VERSION_KEY, '1', 'number');
        Setting::set(OtaSellWindow::MAX_PUBLISHED_KEY, '2026-07-04');
        Http::fake([
            '*availability*' => Http::response(['data' => [
                'RT-1' => $this->remoteAvailabilityThrough('2026-07-03', [
                    '2026-07-04' => null,
                ]),
            ]]),
            '*restrictions*' => Http::response(['data' => [
                'RP-1' => [
                    '2026-07-01' => ['rate' => '80.00'],
                    '2026-07-02' => ['rate' => '80.00'],
                    '2026-07-03' => ['rate' => '80.00'],
                ],
            ]]),
        ]);

        try {
            (new FinalizeOtaSellWindow(1, '2026-07-03'))->handle(
                app(OtaSellWindow::class),
                app(ChannexClient::class),
                app(ChannelSync::class),
            );
            $this->fail('An open remote tail must keep the revision pending.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('availability is not verified', $e->getMessage());
        }

        $this->assertNull(Setting::get(OtaSellWindow::APPLIED_UNTIL_KEY));
    }

    public function test_finalizer_verifies_an_extension_and_its_rates_before_marking_applied(): void
    {
        $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-07-05');
        Setting::set(OtaSellWindow::VERSION_KEY, '1', 'number');
        Setting::set(OtaSellWindow::MAX_PUBLISHED_KEY, '2026-07-05');
        Http::fake([
            '*availability*' => Http::response(['data' => [
                'RT-1' => $this->remoteAvailabilityThrough('2026-07-05'),
            ]]),
            '*restrictions*' => Http::response(['data' => [
                'RP-1' => [
                    '2026-07-01' => ['rate' => '80.00'],
                    '2026-07-02' => ['rate' => '80.00'],
                    '2026-07-03' => ['rate' => '80.00'],
                    '2026-07-04' => ['rate' => '80.00'],
                    '2026-07-05' => ['rate' => '80.00'],
                ],
            ]]),
        ]);

        (new FinalizeOtaSellWindow(1, '2026-07-05'))->handle(
            app(OtaSellWindow::class),
            app(ChannexClient::class),
            app(ChannelSync::class),
        );

        $this->assertSame('2026-07-05', Setting::get(OtaSellWindow::APPLIED_UNTIL_KEY));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/restrictions'));
    }

    public function test_admin_can_preview_then_confirm_a_versioned_change_once(): void
    {
        $admin = $this->admin();
        $this->mappedType();

        $this->actingAs($admin)->postJson(route('channex.sell-window.preview'), [
            'sell_until_date' => '2026-12-31',
            'expected_version' => 0,
        ])->assertOk()->assertExactJson([
            'current_until' => '2027-07-01',
            'requested_until' => '2026-12-31',
            'action' => 'shorten',
            'range_from' => '2027-01-01',
            'range_to' => '2027-07-01',
            'nights' => 182,
            'room_type_count' => 1,
            'version' => 0,
        ]);

        $this->actingAs($admin)->putJson(route('channex.sell-window.update'), [
            'sell_until_date' => '2026-12-31',
            'confirmed' => true,
            'expected_version' => 0,
        ])->assertOk()->assertJson(['status' => 'queued', 'queued' => true]);

        $this->assertSame('2026-12-31', Setting::get(OtaSellWindow::SELL_UNTIL_KEY));
        $this->assertSame(1, (int) Setting::get(OtaSellWindow::VERSION_KEY));
        $this->assertSame(1, AuditLog::where('action', 'channex.sell_window_update')->count());
        Queue::assertPushed(ReconcileOtaSellWindow::class, fn ($job) => $job->version === 1 && $job->target === '2026-12-31');

        Queue::fake();
        $this->actingAs($admin)->putJson(route('channex.sell-window.update'), [
            'sell_until_date' => '2026-12-31',
            'confirmed' => true,
            'expected_version' => 1,
        ])->assertOk()->assertJson(['status' => 'queued', 'queued' => true]);
        Queue::assertPushed(ReconcileOtaSellWindow::class);
        $this->assertSame(1, AuditLog::where('action', 'channex.sell_window_update')->count());
    }

    public function test_update_rejects_missing_confirmation_and_stale_version_without_dispatch(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(route('channex.sell-window.update'), [
            'sell_until_date' => '2026-12-31',
            'confirmed' => false,
            'expected_version' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmed');

        Setting::set(OtaSellWindow::VERSION_KEY, '2', 'number');
        $this->actingAs($admin)->putJson(route('channex.sell-window.update'), [
            'sell_until_date' => '2026-12-31',
            'confirmed' => true,
            'expected_version' => 1,
        ])->assertConflict();

        $this->assertNull(Setting::get(OtaSellWindow::SELL_UNTIL_KEY));
        $this->assertSame(0, AuditLog::where('action', 'channex.sell_window_update')->count());
        Queue::assertNothingPushed();
    }

    public function test_preview_rejects_dates_beyond_the_configured_channex_inventory_length(): void
    {
        $admin = $this->admin();
        config(['services.channex.state_length_days' => 365]);

        $this->actingAs($admin)->postJson(route('channex.sell-window.preview'), [
            'sell_until_date' => '2027-06-30',
            'expected_version' => 0,
        ])->assertOk();

        $this->actingAs($admin)->postJson(route('channex.sell-window.preview'), [
            'sell_until_date' => '2027-07-01',
            'expected_version' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('sell_until_date');

        config(['services.channex.state_length_days' => 999]);
        $this->assertSame(730, app(OtaSellWindow::class)->maxDays());
    }

    public function test_confirming_the_current_legacy_default_pins_and_queues_remote_verification(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('channex.sell-window.preview'), [
            'sell_until_date' => '2027-07-01',
            'expected_version' => 0,
        ])->assertOk()->assertJson([
            'action' => 'pin',
            'nights' => 0,
            'range_from' => null,
            'range_to' => null,
        ]);

        $this->actingAs($admin)->putJson(route('channex.sell-window.update'), [
            'sell_until_date' => '2027-07-01',
            'confirmed' => true,
            'expected_version' => 0,
        ])->assertOk()->assertJson(['status' => 'queued', 'queued' => true]);

        $this->assertSame('2027-07-01', Setting::get(OtaSellWindow::SELL_UNTIL_KEY));
        $this->assertNull(Setting::get(OtaSellWindow::APPLIED_UNTIL_KEY));
        $this->assertSame(1, (int) Setting::get(OtaSellWindow::VERSION_KEY));
        $this->assertSame(1, AuditLog::where('action', 'channex.sell_window_update')->count());
        Queue::assertPushed(ReconcileOtaSellWindow::class, fn ($job) => $job->version === 1);

        Carbon::setTestNow('2026-07-02 09:00:00');
        CarbonImmutable::setTestNow('2026-07-02 09:00:00');
        $this->assertSame('2027-07-01', app(OtaSellWindow::class)->effectiveUntil()->toDateString());
        $this->assertSame('2027-07-02', app(OtaSellWindow::class)->defaultUntil()->toDateString());
    }

    public function test_an_invalid_stored_cutoff_fails_closed_before_any_http_request(): void
    {
        $type = $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, 'not-a-date');

        try {
            app(ChannelSync::class)->pushRoomType($type);
            $this->fail('A corrupt sell-window setting must stop the ARI push.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('OTA ARI push stopped', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_new_revision_clears_an_old_applied_marker_even_when_returning_to_that_date(): void
    {
        $admin = $this->admin();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2027-03-01');
        Setting::set(OtaSellWindow::APPLIED_UNTIL_KEY, '2026-12-31');
        Setting::set(OtaSellWindow::VERSION_KEY, '1', 'number');

        $this->actingAs($admin)->putJson(route('channex.sell-window.update'), [
            'sell_until_date' => '2026-12-31',
            'confirmed' => true,
            'expected_version' => 1,
        ])->assertOk()->assertJson(['status' => 'queued', 'queued' => true]);

        $this->assertNull(Setting::get(OtaSellWindow::APPLIED_UNTIL_KEY));
        Queue::assertPushed(ReconcileOtaSellWindow::class);
    }

    public function test_same_pending_date_requeues_without_a_second_audit_or_version_change(): void
    {
        $admin = $this->admin();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-12-31');
        Setting::set(OtaSellWindow::VERSION_KEY, '4', 'number');

        $this->actingAs($admin)->putJson(route('channex.sell-window.update'), [
            'sell_until_date' => '2026-12-31',
            'confirmed' => true,
            'expected_version' => 4,
        ])->assertOk()->assertJson(['status' => 'queued', 'queued' => true]);

        $this->assertSame(4, (int) Setting::get(OtaSellWindow::VERSION_KEY));
        $this->assertSame(0, AuditLog::where('action', 'channex.sell_window_update')->count());
        Queue::assertPushed(ReconcileOtaSellWindow::class, fn ($job) => $job->version === 4);
    }

    public function test_nightly_fixed_window_mode_and_manual_sync_both_queue_reconciliation(): void
    {
        $admin = $this->admin();
        $this->mappedType();
        Setting::set(OtaSellWindow::SELL_UNTIL_KEY, '2026-12-31');
        Setting::set(OtaSellWindow::VERSION_KEY, '2', 'number');

        $this->artisan('channex:push-ari', [
            '--queue' => true,
            '--reconcile-fixed' => true,
        ])->assertSuccessful();
        Queue::assertPushed(ReconcileOtaSellWindow::class, fn ($job) => $job->version === 2);

        Queue::fake();
        $this->actingAs($admin)->post(route('channex.sync'))->assertRedirect();
        Queue::assertPushed(ReconcileOtaSellWindow::class, fn ($job) => $job->version === 2);
        Queue::assertNotPushed(PushRoomTypeAri::class);
    }

    public function test_nightly_watchdog_schedule_is_single_server_and_non_overlapping(): void
    {
        Artisan::call('schedule:list');
        $event = collect(app(Schedule::class)->events())->first(
            fn ($candidate) => $candidate->description === 'tenants:channex:push-ari',
        );

        $this->assertNotNull($event);
        $this->assertSame('0 4 * * *', $event->getExpression());
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    private function mappedType(): RoomType
    {
        $type = RoomType::create([
            'name' => 'Standard',
            'base_price' => 80,
            'max_occupancy' => 2,
            'amenities' => [],
        ]);
        foreach ([1, 2] as $number) {
            Room::create([
                'room_type_id' => $type->id,
                'room_number' => 'OTA-'.$number,
                'floor' => 1,
                'status' => 'available',
            ]);
        }
        ChannelMapping::create([
            'channel' => 'channex',
            'room_type_id' => $type->id,
            'channex_property_id' => 'PROP-1',
            'channex_room_type_id' => 'RT-1',
            'channex_rate_plan_id' => 'RP-1',
        ]);

        return $type;
    }

    /** @param array<string, int|null> $overrides */
    private function remoteAvailabilityThrough(string $sellUntil, array $overrides = []): array
    {
        $until = CarbonImmutable::parse($sellUntil);
        $availability = [];
        for (
            $date = app(OtaSellWindow::class)->today();
            $date->lte(app(OtaSellWindow::class)->maxUntil());
            $date = $date->addDay()
        ) {
            $availability[$date->toDateString()] = $date->lte($until) ? 2 : 0;
        }

        return array_replace($availability, $overrides);
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }
}
