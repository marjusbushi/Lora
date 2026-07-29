<?php

namespace App\Http\Controllers;

use App\Models\ChannelSyncLog;
use App\Models\OtaReconciliationIssue;
use App\Models\Reservation;
use Illuminate\Http\Request;
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
                    ->whereIn('issue_type', ['missing_in_pms', 'possible_manual_duplicate'])
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
}
