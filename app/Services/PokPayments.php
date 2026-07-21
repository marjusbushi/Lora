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

        // Backward transition after confirmation (refund / chargeback / void) → reverse.
        if (($order['isRefunded'] || $order['isCanceled']) && $reservation->status === 'confirmed') {
            return $this->reverse($reservation, $order['isRefunded'] ? 'refund' : 'cancel');
        }

        $expected = round((float) $reservation->total_amount, 2);
        $currency = strtoupper((string) ($reservation->currency ?: PricingCurrency::code()));
        $paid = $order['isCompleted']
            && ! $order['isCanceled']
            && ! $order['isRefunded']
            && abs($order['finalAmount'] - $expected) < 0.01       // R2 amount bypass
            && strtoupper($order['currencyCode']) === $currency;   // R6 currency

        if (! $paid) {
            return false;
        }

        // Atomic guard (R1 resurrection + R3 SQLite lock no-op): only a still-PENDING, unpaid
        // reservation flips. A released/cancelled hold or an already-confirmed one affects 0 rows.
        $flipped = Reservation::whereKey($reservation->id)
            ->where('status', 'pending')
            ->whereNull('paid_at')
            ->update(['status' => 'confirmed', 'paid_at' => now()]);

        if ($flipped !== 1) {
            return false; // already settled or released — idempotent
        }

        try {
            Payment::create([
                'reservation_id' => $reservation->id,
                'amount' => $expected,
                'method' => 'card',
                'type' => 'payment',
                'pok_order_id' => $reservation->pok_order_id,
                'currency' => $currency,
                'created_by' => $reservation->created_by,
            ]);
        } catch (QueryException $e) {
            // UNIQUE(pok_order_id) — a concurrent path already recorded this order's payment.
        }

        AuditLog::record('payment.pok_capture', $reservation, [
            'pok_order_id' => $reservation->pok_order_id,
            'amount' => $expected,
            'currency' => $currency,
        ]);

        return true;
    }

    /**
     * The order was refunded / cancelled at POK after we confirmed it → free the room and void the
     * folio card payment. Idempotent (guarded on status=confirmed) + flagged for front-desk review.
     */
    public function reverse(Reservation $reservation, string $reason): bool
    {
        $flipped = Reservation::whereKey($reservation->id)
            ->where('status', 'confirmed')
            ->whereNotNull('paid_at')
            ->update(['status' => 'cancelled']);

        if ($flipped !== 1) {
            return false; // already reversed / never confirmed — idempotent
        }

        Payment::where('reservation_id', $reservation->id)
            ->where('method', 'card')
            ->where('pok_order_id', $reservation->pok_order_id)
            ->update(['is_voided' => true]);

        AuditLog::record('payment.pok_'.$reason, $reservation, [
            'pok_order_id' => $reservation->pok_order_id,
        ]);

        return true;
    }
}
