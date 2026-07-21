<?php

namespace Tests\Feature;

use App\Models\ChannelMapping;
use App\Models\ChannelSyncLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ChannexBookingImporter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChannexBookingImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake(); // the imported reservation fires the observer -> queued push
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
            'services.channex.webhook_secret' => 'topsecret',
        ]);
        User::factory()->create(); // a user must exist for created_by
    }

    private function studio(int $rooms = 1, string $channexId = 'RT-1'): RoomType
    {
        $type = RoomType::create(['name' => 'Studio', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        for ($i = 1; $i <= $rooms; $i++) {
            Room::create(['room_type_id' => $type->id, 'room_number' => "S{$i}", 'floor' => 1, 'status' => 'available']);
        }
        ChannelMapping::create([
            'channel' => 'channex', 'room_type_id' => $type->id,
            'channex_property_id' => 'PROP-1', 'channex_room_type_id' => $channexId, 'channex_rate_plan_id' => 'RP-1',
        ]);

        return $type;
    }

    private function revision(array $attrs = []): array
    {
        return [
            'id' => $attrs['id'] ?? 'REV-1',
            'attributes' => array_merge([
                'ota_name' => 'Booking.com',
                'ota_reservation_code' => 'BK123',
                'status' => 'new',
                'arrival_date' => '2026-08-01',
                'departure_date' => '2026-08-03',
                'currency' => 'EUR',
                'amount' => '200.00',
                'ota_commission' => '30.00',
                'inserted_at' => '2026-06-20T09:30:00Z',
                'customer' => ['name' => 'John', 'surname' => 'Doe', 'mail' => 'John@Example.com', 'phone' => '+355691234567', 'country' => 'GB'],
                'rooms' => [[
                    'room_type_id' => 'RT-1',
                    'checkin_date' => '2026-08-01',
                    'checkout_date' => '2026-08-03',
                    'amount' => '200.00',
                    'occupancy' => ['adults' => 2, 'children' => 0],
                ]],
            ], $attrs),
        ];
    }

    public function test_new_booking_creates_guest_and_reservation(): void
    {
        $type = $this->studio();

        app(ChannexBookingImporter::class)->importRevision($this->revision());

        $guest = Guest::where('email', 'john@example.com')->first();
        $this->assertNotNull($guest);
        $this->assertSame('GB', $guest->nationality);
        $this->assertDatabaseHas('reservations', [
            'channel' => 'booking.com',
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'channel_ref' => 'BK123',
            'guest_id' => $guest->id,
            'status' => 'confirmed',
            'adults' => 2,
        ]);
        $res = Reservation::first();
        $this->assertSame('2026-08-01', $res->check_in_date->toDateString());
        $this->assertSame('2026-08-03', $res->check_out_date->toDateString());
        $this->assertEquals(200.0, (float) $res->total_amount);
        $this->assertEquals(30.0, (float) $res->commission_amount);
        $this->assertSame('2026-06-20 09:30:00', $res->booked_at->format('Y-m-d H:i:s'));
        $this->assertSame($type->id, Room::find($res->room_id)->room_type_id);
    }

    public function test_eur_booking_freezes_all_accounting_amounts(): void
    {
        $tenant = Tenant::query()->sole();
        $tenant->update(['currency' => 'ALL']);
        app(TenantContext::class)->set($tenant->fresh());
        Setting::set('pricing.currency', 'EUR');
        Setting::set('financial.fx_all_per_eur', 100, 'number');
        $this->studio();

        app(ChannexBookingImporter::class)->importRevision($this->revision(['payment_collect' => 'ota']));

        $reservation = Reservation::query()->sole();
        $payment = $reservation->payments()->sole();
        $this->assertSame('EUR', $reservation->currency);
        $this->assertSame('20000.00', $reservation->total_amount_base);
        $this->assertSame('3000.00', $reservation->commission_amount_base);
        $this->assertSame('EUR', $payment->currency);
        $this->assertSame('20000.00', $payment->amount_base);
    }

    public function test_reimport_updates_in_place_without_duplicating(): void
    {
        $this->studio();
        $importer = app(ChannexBookingImporter::class);

        $importer->importRevision($this->revision());
        Reservation::first()->update(['created_via' => Reservation::CREATED_VIA_IMPORT]);
        // same ref re-delivered with a new price/dates (modification)
        $importer->importRevision($this->revision([
            'status' => 'modified',
            'inserted_at' => '2026-07-01T12:00:00Z',
            'amount' => '250.00',
            'rooms' => [['room_type_id' => 'RT-1', 'checkin_date' => '2026-08-01', 'checkout_date' => '2026-08-04', 'amount' => '250.00', 'occupancy' => ['adults' => 2]]],
        ]));

        $this->assertSame(1, Reservation::count()); // no duplicate
        $res = Reservation::first();
        $this->assertEquals(250.0, (float) $res->total_amount);
        $this->assertSame('2026-08-04', $res->check_out_date->toDateString());
        $this->assertSame(Reservation::CREATED_VIA_IMPORT, $res->created_via);
        $this->assertSame('2026-06-20 09:30:00', $res->booked_at->format('Y-m-d H:i:s'));
    }

    public function test_cancelled_revision_cancels_the_reservation(): void
    {
        $this->studio();
        $importer = app(ChannexBookingImporter::class);
        $importer->importRevision($this->revision());

        $importer->importRevision($this->revision(['status' => 'cancelled']));

        $this->assertSame('cancelled', Reservation::first()->status);
    }

    public function test_multi_room_booking_creates_grouped_reservations(): void
    {
        $this->studio(2);

        app(ChannexBookingImporter::class)->importRevision($this->revision([
            'rooms' => [
                ['room_type_id' => 'RT-1', 'checkin_date' => '2026-08-01', 'checkout_date' => '2026-08-03', 'amount' => '200.00', 'occupancy' => ['adults' => 2]],
                ['room_type_id' => 'RT-1', 'checkin_date' => '2026-08-01', 'checkout_date' => '2026-08-03', 'amount' => '200.00', 'occupancy' => ['adults' => 2]],
            ],
        ]));

        $this->assertSame(2, Reservation::count());
        $groups = Reservation::pluck('booking_group_id')->unique();
        $this->assertCount(1, $groups);
        $this->assertNotNull($groups->first());
        $this->assertSame(2, Reservation::distinct('room_id')->count('room_id')); // two different rooms
    }

    public function test_overbooked_type_still_creates_and_flags(): void
    {
        $this->studio(1); // a single room
        $importer = app(ChannexBookingImporter::class);
        // first booking takes the only room for the dates
        $importer->importRevision($this->revision(['ota_reservation_code' => 'BK1']));
        // a second, overlapping booking for the same single-room type
        $summary = $importer->importRevision($this->revision(['ota_reservation_code' => 'BK2']));

        $this->assertSame(2, Reservation::where('status', 'confirmed')->count()); // not dropped
        $over = Reservation::where('channel_ref', 'BK2')->first();
        $this->assertStringContainsString('MBI-BOOKIM', $over->notes);
    }

    public function test_unmapped_room_type_is_flagged_not_imported(): void
    {
        $this->studio(1, 'RT-1');

        $summary = app(ChannexBookingImporter::class)->importRevision($this->revision([
            'rooms' => [['room_type_id' => 'UNKNOWN-RT', 'checkin_date' => '2026-08-01', 'checkout_date' => '2026-08-03', 'amount' => '200.00', 'occupancy' => ['adults' => 2]]],
        ]));

        $this->assertSame(0, Reservation::count());
        $this->assertNotEmpty($summary['flagged']);
    }

    public function test_sync_log_records_ids_only_no_guest_pii(): void
    {
        $this->studio();

        app(ChannexBookingImporter::class)->importRevision($this->revision());

        $log = ChannelSyncLog::where('channel', 'booking.com')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(['ref' => 'BK123', 'revision_id' => 'REV-1'], $log->request);
        // no guest PII anywhere in the row
        $blob = json_encode($log->toArray());
        $this->assertStringNotContainsString('john@example.com', strtolower($blob));
        $this->assertStringNotContainsString('doe', strtolower($blob));
    }

    public function test_webhook_rejects_a_bad_secret(): void
    {
        $this->postJson('/channex/webhook', ['event' => 'booking', 'payload' => ['revision_id' => 'REV-1']], ['X-Channex-Webhook-Secret' => 'wrong'])
            ->assertStatus(403);
    }

    public function test_webhook_imports_and_acks_on_a_booking_event(): void
    {
        $this->studio();
        $uuid = '11111111-1111-1111-1111-111111111111';
        Http::fake([
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
            '*booking_revisions/*' => Http::response(['data' => $this->revision(['property_id' => 'PROP-1'])]),
        ]);

        $this->postJson('/channex/webhook', ['event' => 'booking', 'payload' => ['revision_id' => $uuid]], ['X-Channex-Webhook-Secret' => 'topsecret'])
            ->assertOk();

        $this->assertDatabaseHas('reservations', ['channel' => 'booking.com', 'channel_ref' => 'BK123']);
        Http::assertSent(fn ($r) => str_contains($r->url(), "/booking_revisions/{$uuid}/ack"));
    }

    public function test_webhook_is_disabled_when_no_secret_configured(): void
    {
        config(['services.channex.webhook_secret' => '']); // fail CLOSED
        $this->postJson('/channex/webhook', ['event' => 'booking', 'payload' => ['revision_id' => '11111111-1111-1111-1111-111111111111']])
            ->assertStatus(403);
        $this->assertSame(0, Reservation::count());
    }

    public function test_webhook_rejects_a_malformed_revision_id(): void
    {
        $this->postJson('/channex/webhook', ['event' => 'booking', 'payload' => ['revision_id' => '../../properties/secret']], ['X-Channex-Webhook-Secret' => 'topsecret'])
            ->assertStatus(400);
    }

    public function test_webhook_returns_500_and_does_not_ack_on_import_failure(): void
    {
        $this->studio();
        Http::fake(['*booking_revisions/*' => Http::response(['errors' => ['boom']], 500)]); // GET revision fails

        $this->postJson('/channex/webhook', ['event' => 'booking', 'payload' => ['revision_id' => '11111111-1111-1111-1111-111111111111']], ['X-Channex-Webhook-Secret' => 'topsecret'])
            ->assertStatus(500);

        $this->assertSame(0, Reservation::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/ack')); // never ack a failed import
    }

    public function test_webhook_ignores_non_booking_events(): void
    {
        $this->postJson('/channex/webhook', ['event' => 'ari', 'payload' => []], ['X-Channex-Webhook-Secret' => 'topsecret'])
            ->assertOk();
        $this->assertSame(0, Reservation::count());
    }

    public function test_pull_bookings_command_imports_the_feed_and_acks(): void
    {
        $this->studio();
        Http::fake([
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
            '*booking_revisions/feed*' => Http::response(['data' => [$this->revision(['property_id' => 'PROP-1'])], 'meta' => ['total' => 1]]),
        ]);

        $this->artisan('channex:pull-bookings')->assertSuccessful();

        $this->assertDatabaseHas('reservations', ['channel' => 'booking.com', 'channel_ref' => 'BK123']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ack'));
    }

    public function test_pull_bookings_fails_when_unconfigured(): void
    {
        config(['services.channex.api_key' => '']);
        $this->artisan('channex:pull-bookings')->assertFailed();
    }

    public function test_modification_removing_a_room_cancels_the_orphan(): void
    {
        $this->studio(2);
        $importer = app(ChannexBookingImporter::class);
        $room = fn ($amount = '200') => ['room_type_id' => 'RT-1', 'checkin_date' => '2026-08-01', 'checkout_date' => '2026-08-03', 'amount' => $amount, 'occupancy' => ['adults' => 2]];

        $importer->importRevision($this->revision(['rooms' => [$room(), $room()]])); // 2 rooms
        $this->assertSame(2, Reservation::where('status', 'confirmed')->count());

        $importer->importRevision($this->revision(['status' => 'modified', 'rooms' => [$room()]])); // down to 1

        $this->assertSame(1, Reservation::where('status', 'confirmed')->count());
        $this->assertSame(1, Reservation::where('status', 'cancelled')->count()); // the dropped room
    }

    public function test_modification_changing_room_type_cancels_the_old_room(): void
    {
        $this->studio(1, 'RT-STUDIO');
        $suite = RoomType::create(['name' => 'Suite', 'base_price' => 200, 'max_occupancy' => 4, 'amenities' => []]);
        Room::create(['room_type_id' => $suite->id, 'room_number' => 'SU1', 'floor' => 2, 'status' => 'available']);
        ChannelMapping::create(['channel' => 'channex', 'room_type_id' => $suite->id, 'channex_property_id' => 'PROP-1', 'channex_room_type_id' => 'RT-SUITE', 'channex_rate_plan_id' => 'RP-2']);
        $importer = app(ChannexBookingImporter::class);

        $importer->importRevision($this->revision(['rooms' => [['room_type_id' => 'RT-STUDIO', 'checkin_date' => '2026-08-01', 'checkout_date' => '2026-08-03', 'amount' => '100', 'occupancy' => ['adults' => 2]]]]));
        $importer->importRevision($this->revision(['status' => 'modified', 'rooms' => [['room_type_id' => 'RT-SUITE', 'checkin_date' => '2026-08-01', 'checkout_date' => '2026-08-03', 'amount' => '200', 'occupancy' => ['adults' => 2]]]]));

        $this->assertSame(1, Reservation::where('status', 'confirmed')->count()); // no double-book
        $confirmed = Reservation::where('status', 'confirmed')->first();
        $this->assertSame($suite->id, Room::find($confirmed->room_id)->room_type_id);
        $this->assertSame(1, Reservation::where('status', 'cancelled')->count()); // the old studio row
    }

    public function test_serviceable_room_is_preferred_over_maintenance(): void
    {
        $type = RoomType::create(['name' => 'Studio', 'base_price' => 100, 'max_occupancy' => 3, 'amenities' => []]);
        $available = Room::create(['room_type_id' => $type->id, 'room_number' => 'A1', 'floor' => 1, 'status' => 'available']);
        Room::create(['room_type_id' => $type->id, 'room_number' => 'M1', 'floor' => 1, 'status' => 'maintenance']);
        ChannelMapping::create(['channel' => 'channex', 'room_type_id' => $type->id, 'channex_property_id' => 'PROP-1', 'channex_room_type_id' => 'RT-1', 'channex_rate_plan_id' => 'RP-1']);

        app(ChannexBookingImporter::class)->importRevision($this->revision());

        $this->assertSame($available->id, Reservation::first()->room_id); // never the maintenance room
    }

    public function test_reuse_keeps_the_same_room_on_reimport(): void
    {
        $this->studio(2); // two free rooms
        $importer = app(ChannexBookingImporter::class);

        $importer->importRevision($this->revision());
        $roomId = Reservation::first()->room_id;

        // re-deliver: must stay on the SAME room even though a second room is free
        $importer->importRevision($this->revision(['status' => 'modified',
            'rooms' => [['room_type_id' => 'RT-1', 'checkin_date' => '2026-08-01', 'checkout_date' => '2026-08-04', 'amount' => '250', 'occupancy' => ['adults' => 2]]]]));

        $this->assertSame(1, Reservation::where('status', 'confirmed')->count());
        $this->assertSame($roomId, Reservation::where('status', 'confirmed')->first()->room_id);
    }

    public function test_prepaid_ota_booking_records_a_payment_covering_the_room(): void
    {
        $this->studio();
        app(ChannexBookingImporter::class)->importRevision($this->revision(['payment_collect' => 'ota']));

        $res = Reservation::first();
        $this->assertSame('ota', $res->payment_collect);
        $payment = $res->payments()->where('method', 'ota')->first();
        $this->assertNotNull($payment);
        $this->assertEquals(200.0, (float) $payment->amount); // = the room amount
        // folio: paid == room charge -> outstanding for the room is 0 (guest not double-charged)
        $this->assertEquals((float) $res->total_amount, (float) $res->payments()->sum('amount'));
    }

    public function test_pay_at_property_booking_records_no_prepayment(): void
    {
        $this->studio();
        app(ChannexBookingImporter::class)->importRevision($this->revision(['payment_collect' => 'property']));

        $res = Reservation::first();
        $this->assertSame('property', $res->payment_collect);
        $this->assertSame(0, $res->payments()->count()); // guest pays at the hotel
    }

    public function test_ota_prepayment_is_idempotent_on_reimport(): void
    {
        $this->studio();
        $importer = app(ChannexBookingImporter::class);
        $importer->importRevision($this->revision(['payment_collect' => 'ota']));
        $importer->importRevision($this->revision(['payment_collect' => 'ota', 'status' => 'modified']));

        $this->assertSame(1, Reservation::first()->payments()->where('method', 'ota')->count()); // not duplicated
    }

    public function test_foreign_property_revision_is_not_imported_and_flagged_for_no_ack(): void
    {
        $this->studio();

        $summary = app(ChannexBookingImporter::class)->importRevision(
            $this->revision(['property_id' => 'PROP-OTHER']),
            'PROP-1',
        );

        $this->assertSame('foreign_property', $summary['status']);
        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, Guest::count());
        $this->assertTrue(
            ChannelSyncLog::where('action', 'booking.foreign_property')->where('status', 'skipped')->exists(),
        );
    }

    public function test_own_property_revision_imports_normally_with_property_check(): void
    {
        $this->studio();

        $summary = app(ChannexBookingImporter::class)->importRevision(
            $this->revision(['property_id' => 'PROP-1']),
            'PROP-1',
        );

        $this->assertSame(1, $summary['created']);
        $this->assertSame(1, Reservation::count());
    }

    public function test_revision_without_property_identity_is_not_imported(): void
    {
        $this->studio();

        $summary = app(ChannexBookingImporter::class)->importRevision(
            $this->revision(),
            'PROP-1',
        );

        $this->assertSame('foreign_property', $summary['status']);
        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, Guest::count());
    }

    public function test_unmapped_room_type_leaves_a_sync_log_trace(): void
    {
        // No studio()/mapping created — the revision's room type is unknown.
        $summary = app(ChannexBookingImporter::class)->importRevision($this->revision());

        $this->assertSame(0, Reservation::count());
        $this->assertNotEmpty($summary['flagged']);
        $this->assertTrue(
            ChannelSyncLog::where('action', 'booking.room_type_unmapped')->where('status', 'skipped')->exists(),
        );
    }

    public function test_unverifiable_property_refuses_import_entirely(): void
    {
        $this->studio();

        // Expected property UNKNOWN ('' = misconfigured tenant): nothing may be
        // imported or acked — ownership cannot be verified.
        $summary = app(ChannexBookingImporter::class)->importRevision($this->revision(), '');

        $this->assertSame('foreign_property', $summary['status']);
        $this->assertSame(0, Reservation::count());
    }
}
