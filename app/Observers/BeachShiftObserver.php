<?php

namespace App\Observers;

use App\Models\BeachShift;
use App\Services\FinanceLedger;

/**
 * Pas mbylljes: posto diferencën e sirtarit të plazhit në Financë (best-effort,
 * si PosShiftObserver — një dështim i ledger-it s'bllokon kurrë mbylljen).
 */
class BeachShiftObserver
{
    public function updated(BeachShift $shift): void
    {
        try {
            app(FinanceLedger::class)->recordBeachShiftClose($shift);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
