<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ChannelSyncLog;
use App\Models\OtaReconciliationIssue;
use App\Models\Reservation;
use App\Services\ChannexClient;
use App\Services\OtaReservationReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OtaReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        if (! in_array($status, ['open', 'resolved'], true)) {
            $status = 'open';
        }

        $issues = OtaReconciliationIssue::query()
            ->where('status', $status)
            ->orderByRaw("CASE severity WHEN 'error' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('last_detected_at')
            ->paginate(25)
            ->withQueryString();

        $candidateIds = $issues->getCollection()
            ->flatMap(fn (OtaReconciliationIssue $issue) => $issue->details['candidate_reservation_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique();

        $candidates = Reservation::query()
            ->whereIn('id', $candidateIds)
            ->with(['guest:id,first_name,last_name', 'room:id,room_number'])
            ->get()
            ->keyBy('id');

        $issues->through(function (OtaReconciliationIssue $issue) use ($candidates) {
            $details = $issue->details ?? [];

            return [
                'id' => $issue->id,
                'type' => $issue->issue_type,
                'severity' => $issue->severity,
                'status' => $issue->status,
                'channel' => $issue->channel,
                'external_ref' => $issue->external_ref,
                'expected_total' => $issue->expected_total,
                'actual_total' => $issue->actual_total,
                'currency' => $issue->currency,
                'details' => $details,
                'reservation_id' => $issue->reservation_id,
                'resolution' => $issue->resolution,
                'last_detected_at' => $issue->last_detected_at?->toIso8601String(),
                'candidates' => collect($details['candidate_reservation_ids'] ?? [])
                    ->map(fn ($id) => $candidates->get((int) $id))
                    ->filter()
                    ->map(fn (Reservation $reservation) => [
                        'id' => $reservation->id,
                        'guest' => $reservation->guest?->full_name,
                        'room' => $reservation->room?->room_number,
                        'amount' => $reservation->total_amount,
                        'currency' => $reservation->currency,
                    ])
                    ->values(),
            ];
        });

        $open = OtaReconciliationIssue::query()->where('status', 'open');

        return Inertia::render('Reservations/Reconciliation', [
            'issues' => $issues,
            'filters' => ['status' => $status],
            'summary' => [
                'open' => (clone $open)->count(),
                'missing' => (clone $open)->where('issue_type', 'missing_in_pms')->count(),
                'amount' => (clone $open)->where('issue_type', 'amount_mismatch')->count(),
                'manual_candidates' => (clone $open)
                    ->whereIn('issue_type', ['missing_in_pms', 'possible_manual_duplicate', 'cancelled_ota_manual_twin'])
                    ->get()
                    ->filter(fn (OtaReconciliationIssue $issue) => ! empty($issue->details['candidate_reservation_ids']))
                    ->count(),
                'last_checked_at' => ChannelSyncLog::query()
                    ->where('action', 'booking.reconciliation')
                    ->max('created_at')
                    ?? OtaReconciliationIssue::query()->max('last_detected_at'),
            ],
        ]);
    }

    /**
     * Link a suggested manual reservation to the OTA booking behind an audit
     * issue: the manual row receives the booking's channel + reference, so OTA
     * cancellations/modifications finally reach it. This is the ONLY write the
     * reconciliation screen performs, it targets exclusively staff-entered,
     * unlinked, active reservations, and every link is audit-logged.
     */
    public function link(
        Request $request,
        OtaReconciliationIssue $issue,
        OtaReservationReconciler $reconciler,
        ChannexClient $channex,
    ): RedirectResponse {
        $data = $request->validate(['reservation_id' => ['required', 'integer']]);

        if ($issue->status !== OtaReconciliationIssue::STATUS_OPEN) {
            throw ValidationException::withMessages(['reservation_id' => 'Ky rast është zgjidhur tashmë.']);
        }

        $candidateIds = collect($issue->details['candidate_reservation_ids'] ?? [])->map(fn ($id) => (int) $id);
        if (! $candidateIds->contains((int) $data['reservation_id'])) {
            throw ValidationException::withMessages(['reservation_id' => 'Rezervimi nuk është kandidat i këtij rasti.']);
        }

        $reservation = Reservation::findOrFail((int) $data['reservation_id']);

        if ($reservation->created_via !== Reservation::CREATED_VIA_STAFF) {
            throw ValidationException::withMessages(['reservation_id' => 'Vetëm rezervimet e futura manualisht mund të lidhen.']);
        }
        if (filled($reservation->channel_ref)) {
            throw ValidationException::withMessages(['reservation_id' => 'Ky rezervim është tashmë i lidhur me një booking OTA.']);
        }
        if ($reservation->status === 'cancelled') {
            throw ValidationException::withMessages(['reservation_id' => 'Një rezervim i anuluar nuk mund të lidhet.']);
        }

        $held = Reservation::where('channel', $issue->channel)
            ->where('channel_ref', $issue->external_ref)
            ->where('status', '!=', 'cancelled')
            ->exists();
        if ($held) {
            throw ValidationException::withMessages(['reservation_id' => 'Një rezervim tjetër aktiv e mban tashmë këtë numër.']);
        }

        $reservation->forceFill([
            'channel' => $issue->channel,
            'channel_ref' => $issue->external_ref,
        ])->save();

        AuditLog::record('reservation.link_ota', $reservation, [
            'channel' => $issue->channel,
            'channel_ref' => $issue->external_ref,
            'issue_id' => $issue->id,
            'issue_type' => $issue->issue_type,
        ], 'staff');

        // Re-audit this booking right away so the panel tells the truth without
        // waiting for the nightly run. Without Channex access, the link itself
        // cures the unlinked-twin family of complaints — resolve those directly.
        $refreshed = false;
        if ($issue->channex_booking_id && $channex->configured()) {
            try {
                $booking = $channex->getBooking($issue->channex_booking_id);
                if ($booking) {
                    $reconciler->reconcileBooking($booking, $channex->propertyId());
                    $refreshed = true;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if (! $refreshed) {
            OtaReconciliationIssue::query()
                ->where('channel', $issue->channel)
                ->where('external_ref', $issue->external_ref)
                ->whereIn('issue_type', ['missing_in_pms', 'possible_manual_duplicate', 'cancelled_ota_manual_twin'])
                ->where('status', OtaReconciliationIssue::STATUS_OPEN)
                ->update([
                    'status' => OtaReconciliationIssue::STATUS_RESOLVED,
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return back()->with('success', "Rezervimi #{$reservation->id} u lidh me {$issue->channel} #{$issue->external_ref}.");
    }

    /**
     * Staff explanation that closes a difference: the guest extended/changed
     * the stay AT THE DESK, so the PMS legitimately differs from the channel
     * and nothing must be "sent to Booking". Closes every open mismatch card
     * of the same booking and freezes the PMS fingerprint so the nightly
     * checker keeps them closed until the reservation changes again.
     */
    public function resolve(Request $request, OtaReconciliationIssue $issue): RedirectResponse
    {
        $request->validate(['reason' => ['required', Rule::in([OtaReconciliationIssue::RESOLUTION_EXTENDED_ON_DESK])]]);

        if ($issue->status !== OtaReconciliationIssue::STATUS_OPEN) {
            throw ValidationException::withMessages(['reason' => 'Ky rast është zgjidhur tashmë.']);
        }
        if (! in_array($issue->issue_type, OtaReconciliationIssue::DESK_RESOLVABLE_TYPES, true)) {
            throw ValidationException::withMessages(['reason' => 'Vetëm diferencat e shumës ose të datave mbyllen me këtë arsye.']);
        }

        $now = now();
        $closed = OtaReconciliationIssue::query()
            ->where('channel', $issue->channel)
            ->where('external_ref', $issue->external_ref)
            ->whereIn('issue_type', OtaReconciliationIssue::DESK_RESOLVABLE_TYPES)
            ->where('status', OtaReconciliationIssue::STATUS_OPEN)
            ->get()
            ->each(fn (OtaReconciliationIssue $open) => $open->forceFill([
                'status' => OtaReconciliationIssue::STATUS_RESOLVED,
                'resolved_at' => $now,
                'resolution' => OtaReconciliationIssue::RESOLUTION_EXTENDED_ON_DESK,
                'resolved_by' => $request->user()->id,
                'resolution_fingerprint' => OtaReconciliationIssue::fingerprint(
                    $open->actual_total !== null ? (float) $open->actual_total : null,
                    $open->details,
                ),
            ])->save());

        if ($issue->reservation_id && ($reservation = Reservation::find($issue->reservation_id))) {
            AuditLog::record('reservation.reconciliation_extended_on_desk', $reservation, [
                'channel' => $issue->channel,
                'channel_ref' => $issue->external_ref,
                'issues_closed' => $closed->pluck('issue_type')->values()->all(),
            ], 'staff');
        }

        return back()->with('success', 'U shënua si zgjatje në recepsion — '.$closed->count().' diferencë u mbyll.');
    }
}
