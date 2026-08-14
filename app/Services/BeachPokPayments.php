<?php

namespace App\Services;

use App\Models\BeachReservation;

/**
 * Verifikon + shënon pagesën POK të një rezervimi çadre. Pasqyrë e PokPayments
 * të dhomave, por MË E THJESHTË me qëllim: pa Payment/folio (V1 e plazhit s'ka
 * integrimin e financës) dhe pa reverse/refund (jashtë scope — proposal #19).
 */
class BeachPokPayments
{
    public function __construct(private PokClient $pok) {}

    public function settle(BeachReservation $reservation): bool
    {
        if (! $reservation->pok_order_id) {
            return false;
        }

        // E vërteta merret GJITHMONË nga POK me getOrder — kurrë nga klienti.
        $order = $this->pok->getOrder($reservation->pok_order_id);

        $expected = round((float) $reservation->total_amount, 2);
        $currency = strtoupper(PricingCurrency::code());
        $paid = $order['isCompleted']
            && ! $order['isCanceled']
            && ! $order['isRefunded']
            && abs($order['finalAmount'] - $expected) < 0.01
            && strtoupper($order['currencyCode']) === $currency;

        if (! $paid) {
            return false;
        }

        // Guard atomik idempotent: vetëm një rezervim ende i papaguar dhe jo i
        // anulluar shënohet; thirrja e dytë (confirm + webhook) prek 0 rreshta.
        $flipped = BeachReservation::whereKey($reservation->id)
            ->whereNull('paid_at')
            ->where('status', '!=', BeachReservation::STATUS_CANCELLED)
            ->update([
                'status' => BeachReservation::STATUS_CONFIRMED,
                'paid_at' => now(),
                'payment_method' => 'online',
            ]);

        if ($flipped === 1) {
            // Flip-i bulk nuk ndez observer-at — sinkronizo Financën shprehimisht.
            try {
                app(FinanceLedger::class)->recordBeachPayment($reservation->fresh());
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $flipped === 1;
    }
}
