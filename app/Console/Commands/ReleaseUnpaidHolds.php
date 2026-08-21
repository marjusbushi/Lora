<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Reservation;
use App\Services\PokPayments;
use App\Console\Concerns\ResolvesTenantContext;
use Illuminate\Console\Command;

/**
 * A website booking holds its room the moment it is created (status=pending), before the guest
 * pays. If they abandon the POK card form, that hold would block the room forever. This frees
 * holds older than the POK order's ~30-minute expiry that never got a card payment. Runs every
 * 5 minutes (see bootstrap/app.php withSchedule) — needs the server scheduler cron to be live.
 */
class ReleaseUnpaidHolds extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'pok:release-unpaid {--minutes=35 : Age (minutes) after which an unpaid hold is released} {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Cancel pending direct reservations whose POK payment never completed (frees abandoned holds).';

    public function handle(): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $stale = Reservation::query()
            ->where('status', 'pending')
            ->where('channel', 'direct')
            ->whereNotNull('pok_order_id')
            ->whereNull('paid_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        $released = 0;
        $settled = 0;
        $pok = app(PokPayments::class);

        foreach ($stale as $reservation) {
            // Multi-room bookings: the order lives on the PRIMARY (this row); its group
            // members share the hold and must be released together — never one by one.
            $memberIds = $reservation->booking_group_id
                ? Reservation::where('booking_group_id', $reservation->booking_group_id)->pluck('id')
                : collect([$reservation->id]);

            // Belt-and-suspenders: never cancel a group that already carries a card payment.
            if (Payment::whereIn('reservation_id', $memberIds)->where('method', 'card')->exists()) {
                continue;
            }

            // CRITICAL: POK captures money immediately (autoCapture), so NEVER cancel on local
            // state alone. Ask POK first. settle() re-verifies via getOrder and, if the guest
            // actually paid, confirms + records the folio payments (idempotent) — we then keep it.
            // A paid-but-partially-released group THROWS (never returns false) — skipped below.
            try {
                if ($pok->settle($reservation)) {
                    $settled++;
                    continue; // was genuinely paid — settled, do NOT cancel
                }
            } catch (\Throwable $e) {
                report($e);
                continue; // POK unreachable / shape drift → SKIP (fail-safe: never cancel blind)
            }

            // settle() returned false with no error → POK confirms the order is NOT completed →
            // genuinely unpaid/expired → safe to release the WHOLE group. Atomic guard wins any
            // last-second race.
            $affected = Reservation::whereIn('id', $memberIds)
                ->where('status', 'pending')
                ->whereNull('paid_at')
                ->update(['status' => 'cancelled']);
            $released += $affected;

            // Realtime (task #345, gjetje Codex #452): UPDATE-i atomik anashkalon
            // observer-in me qëllim (garda e race-it), ndaj kalendarët e hapur
            // njoftohen këtu me dorë — ndryshe dhoma dukej e zënë deri në refresh.
            if ($affected > 0) {
                foreach ($memberIds as $memberId) {
                    try {
                        event(new \App\Events\ReservationChanged(
                            (int) $reservation->tenant_id,
                            (int) $memberId,
                        ));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
        }

        $this->info("Released {$released} unpaid hold(s)".($settled ? ", settled {$settled} late payment(s)." : '.'));

        return self::SUCCESS;
    }
}
