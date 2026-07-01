<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Console\Command;

/**
 * A website booking holds its room the moment it is created (status=pending), before the guest
 * pays. If they abandon the POK card form, that hold would block the room forever. This frees
 * holds older than the POK order's ~30-minute expiry that never got a card payment. Runs every
 * 5 minutes (see bootstrap/app.php withSchedule) — needs the server scheduler cron to be live.
 */
class ReleaseUnpaidHolds extends Command
{
    protected $signature = 'pok:release-unpaid {--minutes=35 : Age (minutes) after which an unpaid hold is released}';

    protected $description = 'Cancel pending direct reservations whose POK payment never completed (frees abandoned holds).';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $stale = Reservation::query()
            ->where('status', 'pending')
            ->where('channel', 'direct')
            ->whereNotNull('pok_order_id')
            ->whereNull('paid_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        $released = 0;
        foreach ($stale as $reservation) {
            // Belt-and-suspenders: never cancel one that actually carries a card payment.
            if (Payment::where('reservation_id', $reservation->id)->where('method', 'card')->exists()) {
                continue;
            }

            // Atomic guard — a payment landing at this exact moment (pending→confirmed) wins the race.
            $released += Reservation::whereKey($reservation->id)
                ->where('status', 'pending')
                ->whereNull('paid_at')
                ->update(['status' => 'cancelled']);
        }

        $this->info("Released {$released} unpaid hold(s).");

        return self::SUCCESS;
    }
}
