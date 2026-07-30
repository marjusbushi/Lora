<?php

namespace Tests\Feature;

use App\Models\ChannelMapping;
use App\Models\ChannelSyncLog;
use App\Models\Guest;
use App\Models\OtaReconciliationIssue;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\OtaReservationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OtaReservationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $room;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'services.channex.api_key' => 'test-key',
            'services.channex.base_url' => 'https://staging.channex.io/api/v1',
            'services.channex.property_id' => 'PROP-1',
        ]);
        $this->user = User::factory()->create();
        $type = RoomType::create([
            'name' => 'Triple Room',
            'base_price' => 80,
            'max_occupancy' => 3,
            'amenities' => [],
        ]);
        $this->room = Room::create([
            'room_type_id' => $type->id,
            'room_number' => '402',
            'floor' => 4,
            'status' => 'available',
        ]);
        ChannelMapping::create([
            'channel' => 'channex',
            'room_type_id' => $type->id,
            'channex_property_id' => 'PROP-1',
            'channex_room_type_id' => 'RT-TRIPLE',
            'channex_rate_plan_id' => 'RP-1',
        ]);
        $this->guest = Guest::create([
            'first_name' => 'Rebecka',
            'last_name' => 'Ekstrom',
            'email' => 'rebecka@example.test',
        ]);
    }

    public function test_missing_ota_booking_is_reported_without_creating_a_reservation(): void
    {
        $summary = app(OtaReservationReconciler::class)->reconcile(
            [$this->booking()],
            'PROP-1',
        );

        $this->assertSame(1, $summary['issues']);
        $this->assertSame(0, Reservation::count());
        $this->assertDatabaseHas('ota_reconciliation_issues', [
            'channel' => 'booking.com',
            'external_ref' => '5352744650',
            'issue_type' => 'missing_in_pms',
            'status' => 'open',
        ]);
    }

    public function test_staff_booking_is_proposed_as_manual_candidate_but_never_changed(): void
    {
        $manual = $this->reservation([
            'created_via' => Reservation::CREATED_VIA_STAFF,
            'channel' => 'booking.com',
            'channel_ref' => null,
            'total_amount' => 240,
        ]);

        $summary = app(OtaReservationReconciler::class)->reconcile(
            [$this->booking()],
            'PROP-1',
        );

        $issue = OtaReconciliationIssue::where('issue_type', 'missing_in_pms')->sole();
        $this->assertSame(1, $summary['manual_candidates']);
        $this->assertSame([$manual->id], $issue->details['candidate_reservation_ids']);
        $this->assertNull($manual->fresh()->channel_ref);
        $this->assertSame(Reservation::CREATED_VIA_STAFF, $manual->fresh()->created_via);
        $this->assertSame(1, Reservation::count());
    }

    public function test_matching_ota_reservation_is_clean(): void
    {
        $this->reservation([
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'channel' => 'booking.com',
            'channel_ref' => '5352744650',
            'total_amount' => 240,
        ]);

        $summary = app(OtaReservationReconciler::class)->reconcile(
            [$this->booking()],
            'PROP-1',
        );

        $this->assertSame(['checked' => 1, 'clean' => 1, 'issues' => 0, 'manual_candidates' => 0], $summary);
        $this->assertSame(0, OtaReconciliationIssue::count());
    }

    public function test_late_ota_delivery_flags_the_existing_manual_copy_as_a_possible_duplicate(): void
    {
        $manual = $this->reservation([
            'created_via' => Reservation::CREATED_VIA_STAFF,
            'channel' => 'booking.com',
            'channel_ref' => null,
            'total_amount' => 240,
        ]);
        $ota = $this->reservation([
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'channel' => 'booking.com',
            'channel_ref' => '5352744650',
            'total_amount' => 240,
        ]);

        $summary = app(OtaReservationReconciler::class)->reconcile([$this->booking()], 'PROP-1');

        $issue = OtaReconciliationIssue::where('issue_type', 'possible_manual_duplicate')->sole();
        $this->assertSame(1, $summary['manual_candidates']);
        $this->assertSame($ota->id, $issue->reservation_id);
        $this->assertSame([$manual->id], $issue->details['candidate_reservation_ids']);
        $this->assertNull($manual->fresh()->channel_ref);
    }

    public function test_cancelled_remote_booking_missing_from_pms_does_not_create_noise(): void
    {
        $summary = app(OtaReservationReconciler::class)->reconcile([
            $this->booking(['status' => 'cancelled']),
        ], 'PROP-1');

        $this->assertSame(0, $summary['issues']);
        $this->assertSame(0, OtaReconciliationIssue::count());
    }

    public function test_cancelled_ota_booking_with_a_manual_twin_is_flagged(): void
    {
        // The Morvan case: the desk hand-typed an unlinked copy of an OTA
        // booking, then the guest cancelled on the OTA. No PMS row carries the
        // ref, so the cancellation found nothing — but the manual twin still
        // holds the room for a guest who is not coming.
        $manual = $this->reservation([
            'created_via' => Reservation::CREATED_VIA_STAFF,
            'channel' => 'booking.com',
            'channel_ref' => null,
            'total_amount' => 240,
        ]);

        $summary = app(OtaReservationReconciler::class)->reconcile([
            $this->booking(['status' => 'cancelled']),
        ], 'PROP-1');

        $issue = OtaReconciliationIssue::where('issue_type', 'cancelled_ota_manual_twin')->sole();
        $this->assertSame('open', $issue->status);
        $this->assertSame([$manual->id], $issue->details['candidate_reservation_ids']);
        $this->assertSame(1, $summary['manual_candidates']);
        // Report only — the manual reservation is never touched.
        $this->assertSame('confirmed', $manual->fresh()->status);
    }

    public function test_cancelled_stub_without_dates_finds_the_twin_by_guest_name(): void
    {
        // Cancelled-only Channex records can arrive stripped of stay data
        // (production booking 6367603932: arrival/departure null, no rooms).
        // The guest name is then the only usable signal.
        $manual = $this->reservation([
            'created_via' => Reservation::CREATED_VIA_STAFF,
            'channel' => 'booking.com',
            'channel_ref' => null,
            'total_amount' => 100,
        ]);

        app(OtaReservationReconciler::class)->reconcile([$this->booking([
            'status' => 'cancelled',
            'arrival_date' => null,
            'departure_date' => null,
            'amount' => '0.00',
            'rooms' => [],
        ])], 'PROP-1');

        $issue = OtaReconciliationIssue::where('issue_type', 'cancelled_ota_manual_twin')->sole();
        $this->assertSame([$manual->id], $issue->details['candidate_reservation_ids']);
    }

    public function test_cancel_and_replace_does_not_flag_an_amount_mismatch(): void
    {
        // Booking.com re-delivered the booking: the old PMS row was cancelled
        // and a fresh one created for the SAME channel_ref. Only the active
        // row occupies inventory — summing the cancelled sibling would double
        // the local total and raise a phantom amount_mismatch (486 vs 972).
        $this->reservation([
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'channel' => 'booking.com',
            'channel_ref' => '5352744650',
            'status' => 'cancelled',
            'total_amount' => 240,
        ]);
        $this->reservation([
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'channel' => 'booking.com',
            'channel_ref' => '5352744650',
            'total_amount' => 240,
        ]);

        $summary = app(OtaReservationReconciler::class)->reconcile([$this->booking()], 'PROP-1');

        $this->assertSame(0, OtaReconciliationIssue::where('issue_type', 'amount_mismatch')->count());
        $this->assertSame(['checked' => 1, 'clean' => 1, 'issues' => 0, 'manual_candidates' => 0], $summary);
    }

    public function test_issue_totals_report_only_the_active_rows(): void
    {
        // A cancelled sibling exists alongside the live row AND a manual twin.
        // The duplicate warning must report the active €240 as actual_total —
        // an all-rows €480 would mislead the desk comparing it to the OTA €240.
        $this->reservation([
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'channel' => 'booking.com',
            'channel_ref' => '5352744650',
            'status' => 'cancelled',
            'total_amount' => 240,
        ]);
        $this->reservation([
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'channel' => 'booking.com',
            'channel_ref' => '5352744650',
            'total_amount' => 240,
        ]);
        $this->reservation([
            'created_via' => Reservation::CREATED_VIA_STAFF,
            'channel' => 'booking.com',
            'channel_ref' => null,
            'total_amount' => 240,
        ]);

        app(OtaReservationReconciler::class)->reconcile([$this->booking()], 'PROP-1');

        $issue = OtaReconciliationIssue::where('issue_type', 'possible_manual_duplicate')->sole();
        $this->assertSame('240.00', $issue->actual_total);
    }

    public function test_net_of_commission_ota_amount_is_accepted_as_a_match(): void
    {
        // Channex reports Expedia room amounts NET of the OTA commission while
        // the PMS stores the GROSS price (production: 704.70 gross − 126.85
        // commission = 577.85 reported by Channex). Gross OR net must match.
        $this->reservation([
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'channel' => 'expedia',
            'channel_ref' => '2499201075',
            'total_amount' => 704.70,
            'commission_amount' => 126.85,
        ]);

        $summary = app(OtaReservationReconciler::class)->reconcile([$this->booking([
            'ota_name' => 'Expedia',
            'ota_reservation_code' => '2499201075',
            'amount' => '577.85',
            'rooms' => [[
                'room_type_id' => 'RT-TRIPLE',
                'checkin_date' => '2026-08-10',
                'checkout_date' => '2026-08-13',
                'amount' => '577.85',
                'occupancy' => ['adults' => 2, 'children' => 0],
            ]],
        ])], 'PROP-1');

        $this->assertSame(0, OtaReconciliationIssue::where('issue_type', 'amount_mismatch')->count());
        $this->assertSame(['checked' => 1, 'clean' => 1, 'issues' => 0, 'manual_candidates' => 0], $summary);
    }

    public function test_a_total_matching_neither_gross_nor_net_is_still_reported(): void
    {
        $this->reservation([
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'channel' => 'expedia',
            'channel_ref' => '2499201075',
            'total_amount' => 704.70,
            'commission_amount' => 126.85,
        ]);

        app(OtaReservationReconciler::class)->reconcile([$this->booking([
            'ota_name' => 'Expedia',
            'ota_reservation_code' => '2499201075',
            'amount' => '500.00',
            'rooms' => [[
                'room_type_id' => 'RT-TRIPLE',
                'checkin_date' => '2026-08-10',
                'checkout_date' => '2026-08-13',
                'amount' => '500.00',
                'occupancy' => ['adults' => 2, 'children' => 0],
            ]],
        ])], 'PROP-1');

        $issue = OtaReconciliationIssue::where('issue_type', 'amount_mismatch')->sole();
        $this->assertSame('500.00', $issue->expected_total);
        $this->assertSame('704.70', $issue->actual_total);
    }

    public function test_price_difference_is_reported_and_resolves_after_correction(): void
    {
        $reservation = $this->reservation([
            'created_via' => Reservation::CREATED_VIA_CHANNEL_MANAGER,
            'channel' => 'booking.com',
            'channel_ref' => '5352744650',
            'total_amount' => 180,
        ]);
        $reconciler = app(OtaReservationReconciler::class);

        $reconciler->reconcile([$this->booking()], 'PROP-1');

        $issue = OtaReconciliationIssue::where('issue_type', 'amount_mismatch')->sole();
        $this->assertSame('240.00', $issue->expected_total);
        $this->assertSame('180.00', $issue->actual_total);

        $reservation->update(['total_amount' => 240]);
        $reconciler->reconcile([$this->booking()], 'PROP-1');

        $this->assertSame('resolved', $issue->fresh()->status);
        $this->assertNotNull($issue->fresh()->resolved_at);
    }

    public function test_foreign_property_is_ignored(): void
    {
        $summary = app(OtaReservationReconciler::class)->reconcile(
            [$this->booking(['property_id' => 'OTHER-PROPERTY'])],
            'PROP-1',
        );

        $this->assertSame(0, $summary['checked']);
        $this->assertSame(0, OtaReconciliationIssue::count());
    }

    public function test_scheduled_command_runs_a_read_only_audit_and_records_its_summary(): void
    {
        Http::fake([
            '*bookings*' => Http::response([
                'data' => [$this->booking()],
                'meta' => ['total' => 1],
            ]),
        ]);

        $this->artisan('channex:reconcile-bookings')
            ->expectsOutputToContain('Kontrolluar: 1')
            ->assertSuccessful();

        $this->assertSame(0, Reservation::count());
        $this->assertDatabaseHas('ota_reconciliation_issues', [
            'external_ref' => '5352744650',
            'issue_type' => 'missing_in_pms',
        ]);
        $log = ChannelSyncLog::where('action', 'booking.reconciliation')->sole();
        $this->assertSame('ok', $log->status);
        $this->assertSame(1, $log->response['issues']);
    }

    private function reservation(array $overrides): Reservation
    {
        return Reservation::create(array_merge([
            'room_id' => $this->room->id,
            'guest_id' => $this->guest->id,
            'created_by' => $this->user->id,
            'created_via' => Reservation::CREATED_VIA_STAFF,
            'check_in_date' => '2026-08-10',
            'check_out_date' => '2026-08-13',
            'status' => 'confirmed',
            'total_amount' => 240,
            'currency' => 'EUR',
            'adults' => 2,
            'channel' => 'direct',
        ], $overrides));
    }

    private function booking(array $overrides = []): array
    {
        return [
            'id' => 'CHANNEX-BOOKING-1',
            'attributes' => array_merge([
                'property_id' => 'PROP-1',
                'booking_id' => 'CHANNEX-BOOKING-1',
                'ota_name' => 'Booking.com',
                'ota_reservation_code' => '5352744650',
                'status' => 'confirmed',
                'arrival_date' => '2026-08-10',
                'departure_date' => '2026-08-13',
                'currency' => 'EUR',
                'amount' => '240.00',
                'customer' => [
                    'name' => 'Rebecka',
                    'surname' => 'Ekstrom',
                    'mail' => 'rebecka@example.test',
                ],
                'rooms' => [[
                    'room_type_id' => 'RT-TRIPLE',
                    'checkin_date' => '2026-08-10',
                    'checkout_date' => '2026-08-13',
                    'amount' => '240.00',
                    'occupancy' => ['adults' => 2, 'children' => 0],
                ]],
            ], $overrides),
        ];
    }
}
