<?php

namespace App\Services;

use App\Models\ChannelMapping;
use App\Models\ChannelSyncLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maps a Channex booking revision (an OTA reservation — Booking.com, Airbnb, ...)
 * into PMS reservation(s). Idempotent: re-delivering the same revision updates in
 * place instead of duplicating (keyed on channel + channel_ref + room), reusing
 * the room the booking already occupies so a re-delivery never shuffles rooms.
 *
 * An OTA booking is NEVER dropped: if the room type is mapped but every room is
 * taken (the OTA oversold us), it is still created on a room of that type and
 * flagged in the notes for the front desk to resolve.
 *
 * Logs only IDs/refs to ChannelSyncLog — never the guest's name/email/phone.
 */
class ChannexBookingImporter
{
    /** OTA display name (ota_name) -> PMS channel slug (Reservation::CHANNELS). */
    private const OTA_CHANNEL = [
        'booking.com' => 'booking.com',
        'booking' => 'booking.com',
        'expedia' => 'expedia',
        'airbnb' => 'airbnb',
        'agoda' => 'agoda',
    ];

    /**
     * @param  array  $resource  the JSON:API revision ({id, attributes:{...}}) or a bare attributes array
     * @return array{status:string,channel:string,ref:string,created:int,updated:int,cancelled:int,flagged:array<int,string>}
     */
    public function importRevision(array $resource, ?string $expectedPropertyId = null): array
    {
        $rev = $resource['attributes'] ?? $resource;
        $revisionId = $resource['id'] ?? ($rev['id'] ?? null);

        $channel = $this->channel($rev['ota_name'] ?? '');
        $ref = (string) ($rev['ota_reservation_code'] ?? $rev['unique_id'] ?? $revisionId ?? '');
        $cancelled = ($rev['status'] ?? 'new') === 'cancelled';
        $rooms = $rev['rooms'] ?? [];

        $summary = [
            'status' => $rev['status'] ?? 'new', 'channel' => $channel, 'ref' => $ref,
            'created' => 0, 'updated' => 0, 'cancelled' => 0, 'flagged' => [],
        ];

        // One Channex account serves many hotels: a revision belonging to another
        // property (= another tenant) must never be imported here — and the caller
        // must not ack it, or the owning hotel loses the booking permanently.
        // An EMPTY expected property means ownership cannot be verified at all
        // (misconfigured tenant) — refuse everything rather than import blind.
        $revisionProperty = (string) ($rev['property_id'] ?? '');
        if ($expectedPropertyId !== null
            && ($expectedPropertyId === ''
                || $revisionProperty === ''
                || $revisionProperty !== $expectedPropertyId)) {
            $summary['status'] = 'foreign_property';
            $summary['flagged'][] = "revision belongs to property {$revisionProperty} — not ours, skipped";
            $this->log($channel, $ref !== '' ? $ref : (string) $revisionId, $revisionId, 'booking.foreign_property', null, null, 'skipped');

            return $summary;
        }

        if ($ref === '') {
            $summary['flagged'][] = 'revision with no reference — skipped';
            $this->log($channel, (string) $revisionId, $revisionId, 'booking.no_reference', null, null, 'skipped');

            return $summary;
        }

        DB::transaction(function () use ($rev, $channel, $ref, $cancelled, $rooms, $revisionId, &$summary) {
            if ($cancelled) {
                // Per-model saves (not mass ->update()) so model events fire:
                // the status log records the cancellation time, and the observer
                // re-pushes the freed room type's availability to Channex.
                $n = $this->cancelReservations(
                    Reservation::where('channel', $channel)->where('channel_ref', $ref)
                        ->where('status', '!=', 'cancelled'),
                );
                $summary['cancelled'] = $n;
                if ($n === 0) {
                    // Surface it: a cancel that matched nothing means the booking
                    // is still 'confirmed' (or was never imported) and blocks a room.
                    $summary['flagged'][] = "cancel matched no reservation for {$ref}";
                }
                $this->log($channel, $ref, $revisionId, 'booking.cancelled', null, null);

                return;
            }

            $guest = $this->guest($rev['customer'] ?? []);
            $creator = $this->systemUserId();
            $groupId = count($rooms) > 1 ? (string) Str::uuid() : null;
            $taken = [];
            $kept = [];
            $firstRoom = true;
            $paymentCollect = $rev['payment_collect'] ?? null; // 'ota' = prepaid online, 'property' = pay at hotel
            $bookingCurrency = strtoupper((string) ($rev['currency'] ?? PricingCurrency::code()));

            foreach ($rooms as $room) {
                $channexRoomTypeId = $room['room_type_id'] ?? null;
                $roomTypeId = $channexRoomTypeId
                    ? ChannelMapping::where('channel', 'channex')->where('channex_room_type_id', $channexRoomTypeId)->value('room_type_id')
                    : null;
                if (! $roomTypeId) {
                    $summary['flagged'][] = "room type {$channexRoomTypeId} not mapped";
                    // A mapping gap silently loses an OTA booking — leave a trace
                    // the front desk / support can find.
                    $this->log($channel, $ref, $revisionId, 'booking.room_type_unmapped', null, null, 'skipped');

                    continue;
                }

                [$physical, $overbooked] = $this->pickRoom($roomTypeId, $channel, $ref, $room['checkin_date'] ?? null, $room['checkout_date'] ?? null, $taken);
                if (! $physical) {
                    $summary['flagged'][] = "no room for type {$roomTypeId}";
                    $this->log($channel, $ref, $revisionId, 'booking.no_room_available', null, $roomTypeId, 'skipped');

                    continue;
                }
                $taken[] = $physical->id;

                $existing = Reservation::where('channel', $channel)->where('channel_ref', $ref)->where('room_id', $physical->id)->first();
                $existed = $existing !== null;
                $exchangeRate = $existing && strtoupper((string) $existing->currency) === $bookingCurrency
                    ? (float) $existing->exchange_rate
                    : null;
                $amountSnapshot = MoneySnapshot::make((float) ($room['amount'] ?? 0), $bookingCurrency, $exchangeRate);
                $commissionSnapshot = MoneySnapshot::make(
                    $firstRoom ? (float) ($rev['ota_commission'] ?? 0) : 0,
                    $bookingCurrency,
                    $amountSnapshot['exchange_rate'],
                );
                $values = [
                    'guest_id' => $guest->id,
                    'created_by' => $creator,
                    'check_in_date' => $room['checkin_date'] ?? null,
                    'check_out_date' => $room['checkout_date'] ?? null,
                    'status' => 'confirmed',
                    'channex_booking_id' => $rev['booking_id'] ?? null,
                    'total_amount' => (float) ($room['amount'] ?? 0),
                    'currency' => $bookingCurrency,
                    'exchange_rate' => $amountSnapshot['exchange_rate'],
                    'total_amount_base' => $amountSnapshot['amount_base'],
                    'commission_amount' => $firstRoom ? (float) ($rev['ota_commission'] ?? 0) : 0,
                    'commission_amount_base' => $commissionSnapshot['amount_base'],
                    'adults' => max(1, min(255, (int) ($room['occupancy']['adults'] ?? 1))),
                    'children' => max(0, min(255, (int) ($room['occupancy']['children'] ?? 0))),
                    'booking_group_id' => $groupId,
                    'payment_collect' => $paymentCollect,
                    'notes' => trim(($rev['ota_name'] ?? 'OTA')." #{$ref}".($overbooked ? ' — MBI-BOOKIM (s\'ka dhomë të lirë)' : '')),
                ];
                if (! $existed) {
                    $values['created_via'] = Reservation::CREATED_VIA_CHANNEL_MANAGER;
                    // inserted_at is when Channex first received this booking revision.
                    // Preserve it only on creation: later modifications must not reset lead time.
                    $values['booked_at'] = $rev['inserted_at'] ?? now();
                }

                $res = Reservation::updateOrCreate(
                    ['channel' => $channel, 'channel_ref' => $ref, 'room_id' => $physical->id],
                    $values,
                );
                $existed ? $summary['updated']++ : $summary['created']++;
                $firstRoom = false;
                $kept[] = $res->id;

                // Prepaid online via the OTA -> record it as a folio payment so the guest
                // is NOT asked to pay the room again at checkout (the balance then shows
                // only on-site extras). Idempotent: one 'ota' payment per reservation.
                if ($paymentCollect === 'ota') {
                    $res->payments()->updateOrCreate(
                        ['method' => 'ota'],
                        [
                            'amount' => (float) ($room['amount'] ?? 0),
                            'currency' => $bookingCurrency,
                            'exchange_rate' => $amountSnapshot['exchange_rate'],
                            'amount_base' => $amountSnapshot['amount_base'],
                            'type' => 'payment',
                            'created_by' => $creator,
                        ],
                    );
                } else {
                    // Not prepaid (or a prior prepaid booking changed to pay-at-hotel) ->
                    // never leave a stale OTA prepayment that would hide the real balance.
                    $res->payments()->where('method', 'ota')->delete();
                }
                $this->log($channel, $ref, $revisionId, 'booking.'.($existed ? 'modified' : 'new'), $res->id, $roomTypeId);
            }

            // Reconcile: cancel any prior reservation for this booking whose room is
            // no longer in the revision (a room removed, or the room TYPE swapped),
            // so the (channel, channel_ref) set is authoritative per revision and a
            // ghost 'confirmed' row never blocks inventory or double-books.
            $summary['cancelled'] += $this->cancelReservations(
                Reservation::where('channel', $channel)->where('channel_ref', $ref)
                    ->where('status', '!=', 'cancelled')
                    ->whereNotIn('id', $kept ?: [0]),
            );
        });

        return $summary;
    }

    /**
     * Cancel each matching reservation via save() so model events fire
     * (status log + Channex availability re-push) — a mass ->update() would
     * silently bypass both. Returns how many were cancelled.
     */
    private function cancelReservations($query): int
    {
        $stale = $query->get();
        $stale->each(function (Reservation $res) {
            $res->status = 'cancelled';
            $res->save();
        });

        return $stale->count();
    }

    private function channel(string $otaName): string
    {
        $key = strtolower(trim($otaName));

        return self::OTA_CHANNEL[$key] ?? (in_array($key, Reservation::CHANNELS, true) ? $key : 'booking.com');
    }

    private function guest(array $c): Guest
    {
        $first = trim((string) ($c['name'] ?? '')) ?: 'Mysafir';
        $last = trim((string) ($c['surname'] ?? ''));
        $email = trim((string) ($c['mail'] ?? ''));
        $attrs = [
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $c['phone'] ?? null,
            'nationality' => $c['country'] ?? null,
        ];

        return $email !== ''
            ? Guest::firstOrCreate(['email' => strtolower($email)], $attrs)
            : Guest::firstOrCreate(['first_name' => $first, 'last_name' => $last], $attrs);
    }

    /**
     * @return array{0: ?Room, 1: bool} [room, wasOverbooked]
     */
    private function pickRoom(int $roomTypeId, string $channel, string $ref, ?string $in, ?string $out, array $taken): array
    {
        // 1) reuse the room this booking already occupies (stable on re-delivery)
        $existing = Reservation::where('channel', $channel)->where('channel_ref', $ref)
            ->whereNotIn('room_id', $taken ?: [0])
            ->whereHas('room', fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->first();
        if ($existing && $existing->room) {
            return [$existing->room, false];
        }

        // Prefer SERVICEABLE rooms (don't seat a guest in maintenance while a usable
        // room exists — consistent with the availability we push to OTAs).
        $serviceable = Room::where('room_type_id', $roomTypeId)
            ->where('status', '!=', 'maintenance')
            ->whereNotIn('id', $taken ?: [0])->get();

        // 2) a free serviceable room for these dates
        foreach ($serviceable as $room) {
            if ($this->isFree($room->id, $in, $out)) {
                return [$room, false];
            }
        }
        // 3) none free -> accept the OTA booking anyway (overbooked) on a serviceable room
        if ($serviceable->isNotEmpty()) {
            return [$serviceable->first(), true];
        }

        // 4) every room of the type is in maintenance -> STILL never drop the OTA
        // booking: fall back to any room of the type, flagged as overbooked.
        $any = Room::where('room_type_id', $roomTypeId)->whereNotIn('id', $taken ?: [0])->first();

        return [$any, $any !== null];
    }

    private function isFree(int $roomId, ?string $in, ?string $out): bool
    {
        if (! $in || ! $out) {
            return true;
        }

        return ! Reservation::where('room_id', $roomId)
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->whereDate('check_in_date', '<', $out)
            ->whereDate('check_out_date', '>', $in)
            ->exists();
    }

    private function systemUserId(): int
    {
        // withTrashed: resolve the system user even if it was soft-deleted, so imported
        // bookings are attributed to it (not silently to the first admin). Matches the
        // soft-delete-safe lookup in WebsiteController::submitBooking.
        return User::systemForCurrentTenant()->id;
    }

    private function log(string $channel, string $ref, ?string $revisionId, string $action, ?int $reservationId, ?int $roomTypeId, string $status = 'ok'): void
    {
        ChannelSyncLog::record([
            'channel' => $channel,
            'direction' => 'pull',
            'action' => $action,
            'reservation_id' => $reservationId,
            'room_type_id' => $roomTypeId,
            'status' => $status,
            'request' => ['ref' => $ref, 'revision_id' => $revisionId], // IDs only — no guest PII
        ]);
    }
}
