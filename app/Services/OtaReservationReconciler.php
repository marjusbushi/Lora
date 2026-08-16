<?php

namespace App\Services;

use App\Models\ChannelMapping;
use App\Models\OtaReconciliationIssue;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Compares Channex's authoritative booking collection with reservations stored
 * in the PMS. It only reports differences: it never creates, edits, links or
 * cancels a reservation. Manual matches remain a front-desk decision.
 *
 * Persisted details contain IDs and dates only — guest PII is used transiently
 * when ranking a possible manual match and is never stored in the issue.
 */
class OtaReservationReconciler
{
    private const OTA_CHANNEL = [
        'booking.com' => 'booking.com',
        'booking' => 'booking.com',
        'expedia' => 'expedia',
        'airbnb' => 'airbnb',
        'agoda' => 'agoda',
    ];

    /**
     * @return array{checked:int,clean:int,issues:int,manual_candidates:int}
     */
    public function reconcile(array $resources, ?string $expectedPropertyId = null): array
    {
        $summary = ['checked' => 0, 'clean' => 0, 'issues' => 0, 'manual_candidates' => 0];

        foreach ($resources as $resource) {
            $result = $this->reconcileBooking($resource, $expectedPropertyId);
            if ($result === null) {
                continue;
            }

            $summary['checked']++;
            $summary['issues'] += $result['issues'];
            $summary['manual_candidates'] += $result['manual_candidates'];
            if ($result['issues'] === 0) {
                $summary['clean']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{issues:int,manual_candidates:int}|null
     */
    public function reconcileBooking(array $resource, ?string $expectedPropertyId = null): ?array
    {
        $booking = $resource['attributes'] ?? $resource;
        $propertyId = (string) ($booking['property_id'] ?? '');

        if ($expectedPropertyId !== null
            && ($expectedPropertyId === '' || $propertyId === '' || $propertyId !== $expectedPropertyId)) {
            return null;
        }

        $channel = $this->channel((string) ($booking['ota_name'] ?? ''));
        $externalRef = trim((string) (
            $booking['ota_reservation_code']
            ?? $booking['unique_id']
            ?? $resource['id']
            ?? ''
        ));

        if ($externalRef === '') {
            return null;
        }

        $remote = $this->remoteSnapshot($booking);
        if ($remote['departure_date']
            && CarbonImmutable::parse($remote['departure_date'])->lt(today()->subDays(7))) {
            // The operational audit covers current/future stays plus a seven-day
            // look-back. Old Channex history must not flood the desk with legacy
            // bookings that predate this PMS integration.
            $this->storeIssues(
                $channel,
                $externalRef,
                (string) ($booking['booking_id'] ?? $resource['id'] ?? ''),
                $remote['currency'],
                [],
            );

            return null;
        }

        $local = Reservation::query()
            ->where('channel', $channel)
            ->where('channel_ref', $externalRef)
            ->with('room:id,room_type_id')
            ->get();

        $detected = [];
        $manualCandidates = 0;

        if ($local->isEmpty() && $remote['cancelled']) {
            // A cancelled remote booking that never occupied PMS inventory is
            // usually safe — EXCEPT when the desk hand-typed an unlinked copy
            // that still holds the room for a guest who is no longer coming.
            $candidates = $this->cancelledTwinCandidates($booking, $remote);
            if ($candidates->isNotEmpty()) {
                $manualCandidates = $candidates->count();
                $detected['cancelled_ota_manual_twin'] = [
                    'severity' => 'warning',
                    'reservation_id' => null,
                    'expected_total' => $remote['total'],
                    'actual_total' => null,
                    'details' => [
                        'candidate_reservation_ids' => $candidates->pluck('id')->values()->all(),
                        'arrival_date' => $remote['arrival_date'],
                        'departure_date' => $remote['departure_date'],
                        'remote_status' => $remote['status'],
                    ],
                ];
            }
        } elseif ($local->isEmpty()) {
            $candidates = $remote['cancelled'] ? collect() : $this->manualCandidates($booking, $remote);
            $manualCandidates = $candidates->count();
            $detected['missing_in_pms'] = [
                'severity' => 'error',
                'reservation_id' => null,
                'expected_total' => $remote['total'],
                'actual_total' => null,
                'details' => [
                    'candidate_reservation_ids' => $candidates->pluck('id')->values()->all(),
                    'arrival_date' => $remote['arrival_date'],
                    'departure_date' => $remote['departure_date'],
                    'room_count' => $remote['room_count'],
                    'remote_status' => $remote['status'],
                ],
            ];
        } else {
            $active = $local->where('status', '!=', 'cancelled');
            $firstId = $local->first()?->id;
            // Every issue reports the ACTIVE (non-cancelled) total: only active
            // rows occupy inventory, and after a cancel-and-replace revision
            // (old row cancelled, new row created for the same ref) an all-rows
            // sum doubles — one basis keeps every alarm's actual-vs-expected
            // comparison meaningful.
            $activeTotal = round((float) $active->sum('total_amount'), 2);
            $localCancelled = $active->isEmpty();

            if ($localCancelled !== $remote['cancelled']) {
                $detected['status_mismatch'] = [
                    'severity' => 'error',
                    'reservation_id' => $firstId,
                    'expected_total' => $remote['total'],
                    'actual_total' => $activeTotal,
                    'details' => [
                        'remote_status' => $remote['status'],
                        'local_statuses' => $local->pluck('status')->unique()->values()->all(),
                    ],
                ];
            }

            if (! $remote['cancelled']) {
                $candidates = $this->manualCandidates($booking, $remote);
                if ($candidates->isNotEmpty()) {
                    $manualCandidates = $candidates->count();
                    $detected['possible_manual_duplicate'] = [
                        'severity' => 'warning',
                        'reservation_id' => $firstId,
                        'expected_total' => $remote['total'],
                        'actual_total' => $activeTotal,
                        'details' => [
                            'candidate_reservation_ids' => $candidates->pluck('id')->values()->all(),
                            'arrival_date' => $remote['arrival_date'],
                            'departure_date' => $remote['departure_date'],
                        ],
                    ];
                }

                if ($remote['currency'] !== '' && $active->isNotEmpty() && $active->pluck('currency')->filter()->unique()->count() === 1) {
                    $localCurrency = strtoupper((string) $active->first()->currency);
                    // Some OTAs (Expedia Collect) report room amounts NET of the
                    // commission while the PMS stores the GROSS price — either
                    // basis counts as a match.
                    $activeCommission = round((float) $active->sum('commission_amount'), 2);
                    $matchesGross = abs($activeTotal - $remote['total']) <= 0.01;
                    $matchesNet = $activeCommission > 0
                        && abs(round($activeTotal - $activeCommission, 2) - $remote['total']) <= 0.01;
                    if ($localCurrency !== $remote['currency'] || (! $matchesGross && ! $matchesNet)) {
                        $detected['amount_mismatch'] = [
                            'severity' => 'warning',
                            'reservation_id' => $firstId,
                            'expected_total' => $remote['total'],
                            'actual_total' => $activeTotal,
                            'details' => ['local_currency' => $localCurrency],
                        ];
                    }
                }

                $localStays = $active
                    ->map(fn (Reservation $reservation) => $reservation->check_in_date->toDateString().'|'.$reservation->check_out_date->toDateString())
                    ->sort()
                    ->values()
                    ->all();

                if ($localStays !== $remote['stays']) {
                    $detected['stay_mismatch'] = [
                        'severity' => 'warning',
                        'reservation_id' => $firstId,
                        'expected_total' => $remote['total'],
                        'actual_total' => $activeTotal,
                        'details' => [
                            'remote_stays' => $remote['stays'],
                            'local_stays' => $localStays,
                        ],
                    ];
                }
            }
        }

        $this->storeIssues(
            $channel,
            $externalRef,
            (string) ($booking['booking_id'] ?? $resource['id'] ?? ''),
            $remote['currency'],
            $detected,
        );

        return ['issues' => count($detected), 'manual_candidates' => $manualCandidates];
    }

    private function remoteSnapshot(array $booking): array
    {
        $rooms = collect($booking['rooms'] ?? []);
        $status = strtolower((string) ($booking['status'] ?? 'confirmed'));
        $arrival = (string) ($booking['arrival_date'] ?? $rooms->first()['checkin_date'] ?? '');
        $departure = (string) ($booking['departure_date'] ?? $rooms->first()['checkout_date'] ?? '');
        $total = $rooms->isNotEmpty()
            ? (float) $rooms->sum(fn (array $room) => (float) ($room['amount'] ?? 0))
            : (float) ($booking['amount'] ?? 0);

        $stays = $rooms
            ->map(fn (array $room) => (string) ($room['checkin_date'] ?? $arrival).'|'.(string) ($room['checkout_date'] ?? $departure))
            ->sort()
            ->values()
            ->all();

        if ($stays === [] && $arrival !== '' && $departure !== '') {
            $stays = ["{$arrival}|{$departure}"];
        }

        return [
            'status' => $status,
            'cancelled' => $status === 'cancelled',
            'currency' => strtoupper((string) ($booking['currency'] ?? '')),
            'total' => round($total, 2),
            'arrival_date' => $arrival !== '' ? $arrival : null,
            'departure_date' => $departure !== '' ? $departure : null,
            'room_count' => max(1, $rooms->count()),
            'stays' => $stays,
        ];
    }

    /**
     * Manual twins of a CANCELLED remote booking. With stay dates present the
     * regular candidate matching applies (dates + one more signal). Cancelled
     * -only Channex records can arrive stripped of stay data, so an exact
     * guest-name match on an unlinked, still-upcoming staff reservation is
     * the fallback signal.
     */
    private function cancelledTwinCandidates(array $booking, array $remote): Collection
    {
        if ($remote['arrival_date'] && $remote['departure_date']) {
            return $this->manualCandidates($booking, $remote);
        }

        $customer = $booking['customer'] ?? [];
        $remoteName = $this->normalizeName(trim(
            (string) ($customer['name'] ?? '').' '.(string) ($customer['surname'] ?? '')
        ));
        if ($remoteName === '') {
            return collect();
        }

        return Reservation::query()
            ->where('created_via', Reservation::CREATED_VIA_STAFF)
            ->where(fn (Builder $query) => $query->whereNull('channel_ref')->orWhere('channel_ref', ''))
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->whereDate('check_out_date', '>=', today())
            ->with('guest:id,first_name,last_name')
            ->get()
            ->filter(fn (Reservation $reservation) => $this->normalizeName($reservation->guest?->full_name ?? '') === $remoteName)
            ->take(3)
            ->values();
    }

    private function manualCandidates(array $booking, array $remote): Collection
    {
        if (! $remote['arrival_date'] || ! $remote['departure_date']) {
            return collect();
        }

        $roomTypeIds = collect($booking['rooms'] ?? [])
            ->pluck('room_type_id')
            ->filter()
            ->map(fn ($channexRoomTypeId) => ChannelMapping::query()
                ->where('channel', 'channex')
                ->where('channex_room_type_id', $channexRoomTypeId)
                ->value('room_type_id'))
            ->filter()
            ->unique()
            ->values();

        $customer = $booking['customer'] ?? [];
        $remoteName = $this->normalizeName(trim(
            (string) ($customer['name'] ?? '').' '.(string) ($customer['surname'] ?? '')
        ));
        $remoteEmail = strtolower(trim((string) ($customer['mail'] ?? '')));
        $remotePhone = preg_replace('/\D+/', '', (string) ($customer['phone'] ?? ''));

        return Reservation::query()
            ->where('created_via', Reservation::CREATED_VIA_STAFF)
            ->where(fn (Builder $query) => $query->whereNull('channel_ref')->orWhere('channel_ref', ''))
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->whereDate('check_in_date', $remote['arrival_date'])
            ->whereDate('check_out_date', $remote['departure_date'])
            ->when(
                $roomTypeIds->isNotEmpty(),
                fn (Builder $query) => $query->whereHas('room', fn (Builder $room) => $room->whereIn('room_type_id', $roomTypeIds))
            )
            ->with(['guest:id,first_name,last_name,email,phone', 'room:id,room_number,room_type_id'])
            ->get()
            ->map(function (Reservation $reservation) use ($remote, $remoteName, $remoteEmail, $remotePhone) {
                $localName = $this->normalizeName($reservation->guest?->full_name ?? '');
                $nameMatch = $remoteName !== '' && $remoteName === $localName;
                $emailMatch = $remoteEmail !== '' && $remoteEmail === strtolower(trim((string) $reservation->guest?->email));
                $localPhone = preg_replace('/\D+/', '', (string) $reservation->guest?->phone);
                $phoneMatch = $remotePhone !== '' && $remotePhone === $localPhone;
                $amountMatch = abs((float) $reservation->total_amount - $remote['total']) <= 0.01;
                $reservation->setAttribute('reconciliation_score',
                    ($nameMatch ? 4 : 0) + ($emailMatch ? 5 : 0) + ($phoneMatch ? 5 : 0) + ($amountMatch ? 3 : 0)
                );

                return $reservation;
            })
            // Exact dates and room type are mandatory above. Require one more
            // meaningful signal before presenting a manual candidate.
            ->filter(fn (Reservation $reservation) => (int) $reservation->reconciliation_score >= 4)
            ->sortByDesc('reconciliation_score')
            ->take(max(3, $remote['room_count']))
            ->values();
    }

    private function storeIssues(
        string $channel,
        string $externalRef,
        string $channexBookingId,
        string $currency,
        array $detected,
    ): void {
        $now = now();

        foreach ($detected as $type => $data) {
            $issue = OtaReconciliationIssue::firstOrNew([
                'channel' => $channel,
                'external_ref' => $externalRef,
                'issue_type' => $type,
            ]);

            if (! $issue->exists) {
                $issue->first_detected_at = $now;
            }

            $issue->fill([
                'reservation_id' => $data['reservation_id'],
                'channex_booking_id' => $channexBookingId !== '' ? $channexBookingId : null,
                'severity' => $data['severity'],
                'status' => OtaReconciliationIssue::STATUS_OPEN,
                'expected_total' => $data['expected_total'],
                'actual_total' => $data['actual_total'],
                'currency' => $currency !== '' ? $currency : null,
                'details' => $data['details'],
                'last_detected_at' => $now,
                'resolved_at' => null,
            ])->save();
        }

        $stale = OtaReconciliationIssue::query()
            ->where('channel', $channel)
            ->where('external_ref', $externalRef)
            ->where('status', OtaReconciliationIssue::STATUS_OPEN);

        if ($detected !== []) {
            $stale->whereNotIn('issue_type', array_keys($detected));
        }

        $stale->update([
            'status' => OtaReconciliationIssue::STATUS_RESOLVED,
            'resolved_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function channel(string $otaName): string
    {
        $key = strtolower(trim($otaName));

        return self::OTA_CHANNEL[$key] ?? (in_array($key, Reservation::CHANNELS, true) ? $key : 'booking.com');
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(
            iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($name)) ?: trim($name)
        ));
    }
}
