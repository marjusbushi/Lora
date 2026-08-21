<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Database\QueryException;

/**
 * The ONE verified path for settling / reversing a POK card payment. Used by the browser confirm,
 * the webhook, the payment page, AND the release-unpaid-holds cron — so every caller re-verifies
 * with POK (never local state) and shares the same idempotent, hardened logic.
 *
 * WHY it matters: POK orders are autoCapture=true — money is captured the instant the guest submits
 * the card, independent of our DB. So confirmation must ALWAYS be driven by PokClient::getOrder, and
 * anything that would cancel a hold MUST first ask POK whether it was actually paid.
 */
class PokPayments
{
    public function __construct(private PokClient $pok) {}

    /**
     * Re-verify the order with POK and reconcile the reservation:
     *  - genuinely paid + still pending  → confirm + record the folio card payment (idempotent)
     *  - refunded/cancelled at POK + already confirmed → reverse (free the room + void the payment)
     *  - anything else → no-op (returns false)
     * Throws (does NOT swallow) if POK is unreachable or the response shape drifts — callers decide.
     */
    public function settle(Reservation $reservation): bool
    {
        if (! $reservation->pok_order_id) {
            return false;
        }

        $order = $this->pok->getOrder($reservation->pok_order_id); // throws on non-2xx / missing amount

        // Multi-room direct bookings: ONE order pays the whole booking group. The order id
        // lives on the primary reservation; the members share its fate.
        $members = $this->members($reservation);

        // Backward transition after confirmation (refund / chargeback / void) → reverse.
        if (($order['isRefunded'] || $order['isCanceled']) && $members->contains(fn ($m) => $m->status === 'confirmed')) {
            return $this->reverse($reservation, $order['isRefunded'] ? 'refund' : 'cancel');
        }

        $expected = round((float) $members->sum('total_amount'), 2);
        $currency = strtoupper((string) ($reservation->currency ?: PricingCurrency::code()));
        $paid = $order['isCompleted']
            && ! $order['isCanceled']
            && ! $order['isRefunded']
            && abs($order['finalAmount'] - $expected) < 0.01       // R2 amount bypass
            && strtoupper($order['currencyCode']) === $currency;   // R6 currency

        if (! $paid) {
            return false;
        }

        $pendingIds = $members->filter(fn ($m) => $m->status === 'pending' && $m->paid_at === null)->pluck('id');

        if ($pendingIds->isEmpty()) {
            return false; // already settled or released — idempotent
        }

        // A PAID order whose group is only partially pending means someone released/cancelled a
        // member before the money landed. Never confirm half a group, and never return false —
        // false tells the release cron "unpaid, safe to cancel", which would throw away a paid
        // booking. Fail LOUD (the cron's catch skips on throw) and flag for the front desk.
        if ($pendingIds->count() < $members->count()) {
            AuditLog::record('payment.pok_group_partial', $reservation, [
                'pok_order_id' => $reservation->pok_order_id,
                'booking_group_id' => $reservation->booking_group_id,
                'pending' => $pendingIds->all(),
                'members' => $members->pluck('id')->all(),
            ]);

            throw new \RuntimeException(
                "POK order {$reservation->pok_order_id} is paid but its booking group is partially released — manual reconciliation required."
            );
        }

        // Atomic guard (R1 resurrection + R3 SQLite lock no-op): only still-PENDING, unpaid
        // rows flip — the WHOLE group in one statement. A concurrent settle sees 0 rows.
        $flipped = Reservation::whereIn('id', $pendingIds)
            ->where('status', 'pending')
            ->whereNull('paid_at')
            ->update(['status' => 'confirmed', 'paid_at' => now()]);

        if ($flipped === 0) {
            return false; // a concurrent path won the whole flip — idempotent
        }

        if ($flipped < $pendingIds->count()) {
            // A concurrent canceller raced us mid-flip: part of the group is confirmed, part is
            // not. Same fail-loud contract as the partial-pending guard above.
            AuditLog::record('payment.pok_group_partial', $reservation, [
                'pok_order_id' => $reservation->pok_order_id,
                'booking_group_id' => $reservation->booking_group_id,
                'flipped' => $flipped,
                'expected_flips' => $pendingIds->count(),
            ]);

            throw new \RuntimeException(
                "POK order {$reservation->pok_order_id} settled only {$flipped}/{$pendingIds->count()} group members — manual reconciliation required."
            );
        }

        // One folio card-payment row PER member with its own share (accurate per-reservation
        // balances). UNIQUE(pok_order_id, reservation_id) keeps the double-record guard.
        foreach ($members as $member) {
            try {
                Payment::create([
                    'reservation_id' => $member->id,
                    'amount' => round((float) $member->total_amount, 2),
                    'method' => 'card',
                    'type' => 'payment',
                    'pok_order_id' => $reservation->pok_order_id,
                    'currency' => $currency,
                    'created_by' => $member->created_by,
                ]);
            } catch (QueryException $e) {
                // UNIQUE(pok_order_id, reservation_id) — a concurrent path already recorded it.
            }

            AuditLog::record('payment.pok_capture', $member, [
                'pok_order_id' => $reservation->pok_order_id,
                'booking_group_id' => $reservation->booking_group_id,
                'amount' => round((float) $member->total_amount, 2),
                'currency' => $currency,
            ]);
        }

        // Realtime (task #349, gjetje e Marjusit live): UPDATE-i atomik anashkalon
        // observer-in me qëllim (garda e race-it) — njoftohet me dorë, dhe PAS
        // regjistrimit të pagesës: një reload i shpejtë s'sheh kurrë status të
        // konfirmuar me shuma bosh (gjetje Codex #454). Për grupet: çdo anëtar.
        $members->each(fn ($member) => $this->broadcastChange($member));

        // Hapi 3 (task #365): rezervim i krijuar nga biseda e Lora-s → pas
        // pagesës mysafiri merr përmbledhjen e konfirmimit në të njëjtin
        // thread. Vetëm në flip-in e parë (idempotent më lart) dhe best-effort
        // — një radhë e ngecur s'e prek dot konfirmimin e pagesës.
        if ($reservation->created_via === Reservation::CREATED_VIA_AI) {
            try {
                \App\Jobs\SendAiBookingConfirmation::dispatch($reservation->id);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return true;
    }

    /**
     * The order was refunded / cancelled at POK after we confirmed it → free the room and void the
     * folio card payment. Idempotent (guarded on status=confirmed) + flagged for front-desk review.
     */
    public function reverse(Reservation $reservation, string $reason): bool
    {
        // POK refunds/cancels the ORDER, and the order paid the whole group — reverse every
        // confirmed member together (a single reservation is just a group of one).
        $members = $this->members($reservation);

        // withTrashed on the flip too — the default scope would skip a soft-deleted member,
        // leaving its captured payment un-voided after a full refund (Codex P1, PR #527).
        $flipped = Reservation::withTrashed()
            ->whereIn('id', $members->pluck('id'))
            ->where('status', 'confirmed')
            ->whereNotNull('paid_at')
            ->update(['status' => 'cancelled']);

        if ($flipped === 0) {
            return false; // already reversed / never confirmed — idempotent
        }

        Payment::whereIn('reservation_id', $members->pluck('id'))
            ->where('method', 'card')
            ->where('pok_order_id', $reservation->pok_order_id)
            ->update(['is_voided' => true]);

        foreach ($members as $member) {
            AuditLog::record('payment.pok_'.$reason, $member, [
                'pok_order_id' => $reservation->pok_order_id,
                'booking_group_id' => $reservation->booking_group_id,
            ]);

            // Realtime (task #349): edhe rikthimi shfaqet vetiu — PAS void-it të
            // pagesës, që reload-i të mos shohë kurrë gjendje gjysmake (Codex #454).
            $this->broadcastChange($member);
        }

        return true;
    }

    /**
     * The reservations settled/reversed together with this one: its whole booking group when
     * one exists (multi-room direct booking — the order id lives on the primary), otherwise
     * just itself. Ordered by id so the first row is always the primary.
     *
     * @return \Illuminate\Support\Collection<int, Reservation>
     */
    private function members(Reservation $reservation)
    {
        if (! $reservation->booking_group_id) {
            return collect([$reservation]);
        }

        // withTrashed: a soft-deleted member still belongs to the ORDER — its amount is part
        // of what POK captured, its folio payment must be voided on refund, and a deleted
        // pending member must trip the partial-group guard, never silently shrink the sum
        // (Codex P1, PR #527).
        return Reservation::withTrashed()
            ->where('booking_group_id', $reservation->booking_group_id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Njofto ekranet e hapura pas një UPDATE-i atomik të suksesshëm — i njëjti
     * patern si ReservationObserver (memoria #136). Dështimi i transmetimit
     * (Reverb offline) s'guxon të prishë kurrë rrjedhën e pagesës.
     */
    private function broadcastChange(Reservation $reservation): void
    {
        try {
            event(new \App\Events\ReservationChanged(
                (int) $reservation->tenant_id,
                (int) $reservation->id,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
