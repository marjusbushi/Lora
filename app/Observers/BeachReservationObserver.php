<?php

namespace App\Observers;

use App\Models\BeachReservation;
use App\Services\FinanceLedger;
use Illuminate\Support\Facades\Log;

/**
 * Mban Financën në sinkron me pagesat e çadrave — best-effort si PaymentObserver:
 * një dështim i ledger-it s'guxon KURRË të bllokojë vetë pagesën në plazh.
 */
class BeachReservationObserver
{
    public function created(BeachReservation $reservation): void
    {
        $this->sync($reservation);
    }

    public function updated(BeachReservation $reservation): void
    {
        $this->sync($reservation); // mbulon edhe unmark/anullim: rreshti i ledger-it hiqet
    }

    public function deleted(BeachReservation $reservation): void
    {
        try {
            app(FinanceLedger::class)->removeFor($reservation);
        } catch (\Throwable $e) {
            Log::warning('Beach finance ledger cleanup failed: '.$e->getMessage());
        }
    }

    private function sync(BeachReservation $reservation): void
    {
        try {
            app(FinanceLedger::class)->recordBeachPayment($reservation);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
