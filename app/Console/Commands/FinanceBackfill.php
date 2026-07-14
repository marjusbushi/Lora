<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantContext;
use App\Models\FinanceAccount;
use App\Models\Payment;
use App\Models\PosShift;
use App\Services\FinanceLedger;
use Illuminate\Console\Command;

/**
 * Seed / heal the finance ledger from history: every folio payment and every
 * closed POS shift since --from gets its ledger row. Fully idempotent (the
 * ledger keys on the source record) — safe to run any number of times.
 *
 *   php artisan finance:backfill --from=2026-01-01
 */
class FinanceBackfill extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'finance:backfill {--from=2026-01-01 : Backfill records created on/after this date} {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Create finance ledger rows from existing folio payments + closed POS shifts (idempotent)';

    public function handle(FinanceLedger $ledger): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        FinanceAccount::ensureDefaults();
        $from = (string) $this->option('from');

        $payments = 0;
        Payment::where('created_at', '>=', $from)->orderBy('id')->each(function (Payment $p) use ($ledger, &$payments) {
            if ($ledger->recordFolioPayment($p)) {
                $payments++;
            }
        });

        $shifts = 0;
        PosShift::where('status', 'closed')->where('closed_at', '>=', $from)->orderBy('id')->each(function (PosShift $s) use ($ledger, &$shifts) {
            if ($ledger->recordShiftClose($s)) {
                $shifts++;
            }
        });

        $this->info("Ledger synced: {$payments} folio payment(s), {$shifts} shift close(s) — re-runs are no-ops.");

        return self::SUCCESS;
    }
}
