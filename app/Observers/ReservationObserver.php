<?php

namespace App\Observers;

use App\Jobs\PushRoomTypeAri;
use App\Mail\NewReservationMail;
use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use App\Models\Room;
use App\Models\Setting;
use App\Services\ChannexClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ReservationObserver
{
    /**
     * Email the hotel on every new reservation. Wrapped so a mail failure
     * (no SMTP, server down, etc.) NEVER breaks the booking.
     */
    public function created(Reservation $reservation): void
    {
        $this->logStatus($reservation, null, (string) $reservation->status);
        AuditLog::record('reservation.created', $reservation, [
            'changes' => $this->changes($reservation, true),
        ]);

        $to = Setting::get('hotel.email');
        if (! $to || ! Setting::get('notifications.email_new_reservations', true)) {
            return;
        }

        try {
            $reservation->loadMissing(['guest', 'room.roomType']);
            Mail::to($to)->send(new NewReservationMail($reservation));
        } catch (\Throwable $e) {
            Log::warning('New-reservation email failed: '.$e->getMessage());
        }
    }

    /**
     * Append-only status history: every transition (confirm, check-in/out,
     * cancel, no-show) gets a row — this is where exact cancellation times
     * come from for pricing analytics. 'updated' still sees getOriginal().
     */
    public function updated(Reservation $reservation): void
    {
        if ($reservation->wasChanged('status')) {
            $this->logStatus(
                $reservation,
                (string) $reservation->getOriginal('status'),
                (string) $reservation->status,
            );
        }

        $changes = $this->changes($reservation);
        if ($changes !== []) {
            AuditLog::record($this->auditAction($reservation), $reservation, [
                'changes' => $changes,
            ]);
        }
    }

    /**
     * Any create / status change / check-in-out / cancel changes how many rooms
     * of this type are free, so re-push that room type's availability to Channex.
     * 'saved' fires on create AND update, covering every booking path at once.
     */
    public function saved(Reservation $reservation): void
    {
        $this->syncChannel($reservation);
    }

    /** Best-effort: an audit-log failure must never break a booking write. */
    private function logStatus(Reservation $reservation, ?string $from, string $to): void
    {
        try {
            ReservationStatusLog::create([
                'reservation_id' => $reservation->id,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => auth()->id(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Reservation status log failed: '.$e->getMessage());
        }
    }

    public function deleted(Reservation $reservation): void
    {
        AuditLog::record('reservation.deleted', $reservation, [
            'changes' => $this->changes($reservation, true),
        ]);
        $this->syncChannel($reservation);
    }

    /** @return array<string, array{from:mixed,to:mixed,from_label?:string,to_label?:string}> */
    private function changes(Reservation $reservation, bool $created = false): array
    {
        $fields = [
            'room_id', 'guest_id', 'check_in_date', 'check_out_date', 'status',
            'total_amount', 'adults', 'children', 'notes', 'channel', 'channel_ref',
            'payment_collect', 'eta', 'etd', 'early_check_in', 'late_check_out', 'no_show_at',
        ];

        $changes = [];
        foreach ($fields as $field) {
            if (! $created && ! $reservation->wasChanged($field)) {
                continue;
            }

            $to = $this->auditValue($reservation->getAttribute($field));
            if ($created && $to === null) {
                continue;
            }

            $changes[$field] = [
                'from' => $created ? null : $this->auditValue($reservation->getOriginal($field)),
                'to' => $to,
            ];
        }

        if (isset($changes['room_id'])) {
            $roomIds = array_filter([$changes['room_id']['from'], $changes['room_id']['to']]);
            $roomLabels = Room::withTrashed()->whereKey($roomIds)->pluck('room_number', 'id');
            $changes['room_id']['from_label'] = $roomLabels[$changes['room_id']['from']] ?? null;
            $changes['room_id']['to_label'] = $roomLabels[$changes['room_id']['to']] ?? null;
        }

        if (isset($changes['guest_id'])) {
            $guestIds = array_filter([$changes['guest_id']['from'], $changes['guest_id']['to']]);
            $guestLabels = Guest::withTrashed()->whereKey($guestIds)->get()
                ->mapWithKeys(fn (Guest $guest) => [$guest->id => $guest->full_name]);
            $changes['guest_id']['from_label'] = $guestLabels[$changes['guest_id']['from']] ?? null;
            $changes['guest_id']['to_label'] = $guestLabels[$changes['guest_id']['to']] ?? null;
        }

        return $changes;
    }

    private function auditAction(Reservation $reservation): string
    {
        if ($reservation->wasChanged('status')) {
            return match ($reservation->status) {
                'checked_in' => 'reservation.check_in',
                'checked_out' => 'reservation.check_out',
                'cancelled' => 'reservation.cancel',
                default => 'reservation.updated',
            };
        }

        if ($reservation->wasChanged('room_id')) {
            return 'reservation.move_room';
        }

        return 'reservation.updated';
    }

    private function auditValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(str_contains($value->format('c'), 'T00:00:00') ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        return $value;
    }

    private function syncChannel(Reservation $reservation): void
    {
        // Only when Channex is wired up — never queue no-op jobs otherwise.
        if (! app(ChannexClient::class)->configured()) {
            return;
        }

        // A reservation event only changes availability for the nights it occupies,
        // so push ONLY that window — not the whole default year. If nothing in the
        // sellable future changed (the stay is entirely in the past), don't push.
        [$from, $to] = $this->changedWindow($reservation);
        if ($from === null) {
            return;
        }

        $roomIds = collect([$reservation->room_id]);

        // A reservation MOVED to another room frees a room on the OLD room's type
        // too, so re-push that type as well (deduped if it's the same type).
        if ($reservation->wasChanged('room_id') && $reservation->getOriginal('room_id')) {
            $roomIds->push($reservation->getOriginal('room_id'));
        }

        Room::whereKey($roomIds->unique()->all())
            ->pluck('room_type_id')
            ->unique()
            ->each(fn ($roomTypeId) => PushRoomTypeAri::dispatch($roomTypeId, $from, $to));
    }

    /**
     * The inclusive Y-m-d window whose availability THIS reservation event affects:
     * the occupied nights [check_in .. check_out-1] (half-open — the check-out day
     * is free), widened to the UNION of the old and new nights when the dates moved
     * (so freed dates are re-pushed too), and floored at today (past dates are
     * unsellable and the full-window path never pushes them either). Returns
     * [null, null] when the whole window is in the past — nothing to push.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function changedWindow(Reservation $reservation): array
    {
        $checkIn = CarbonImmutable::parse($reservation->check_in_date);
        $checkOut = CarbonImmutable::parse($reservation->check_out_date);

        // On an update that moved the dates, getOriginal() still holds the pre-change
        // values ('saved' fires before syncOriginal), so cover both old and new.
        if ($reservation->wasChanged('check_in_date') && $reservation->getOriginal('check_in_date')) {
            $checkIn = $checkIn->min(CarbonImmutable::parse($reservation->getOriginal('check_in_date')));
        }
        if ($reservation->wasChanged('check_out_date') && $reservation->getOriginal('check_out_date')) {
            $checkOut = $checkOut->max(CarbonImmutable::parse($reservation->getOriginal('check_out_date')));
        }

        $lastNight = $checkOut->subDay(); // check-out day is free
        $today = CarbonImmutable::today();

        if ($lastNight->lt($today)) {
            return [null, null];
        }

        $from = $checkIn->lt($today) ? $today : $checkIn;

        return [$from->toDateString(), $lastNight->toDateString()];
    }
}
