<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantContext;
use App\Models\ChannelSyncLog;
use App\Services\ChannexClient;
use App\Services\OtaReservationReconciler;
use Illuminate\Console\Command;

class ChannexReconcileBookings extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'channex:reconcile-bookings {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Audit Channex bookings against PMS reservations without changing either side';

    public function handle(ChannexClient $channex, OtaReservationReconciler $reconciler): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        if (! $channex->configured() || $channex->propertyId() === '') {
            $this->error('Integrimi Channex nuk është konfiguruar plotësisht për këtë hotel.');

            return self::FAILURE;
        }

        try {
            $summary = $reconciler->reconcile(
                $channex->listBookings(),
                $channex->propertyId(),
            );

            ChannelSyncLog::record([
                'channel' => 'channex',
                'direction' => 'pull',
                'action' => 'booking.reconciliation',
                'status' => 'ok',
                'request' => ['property_id' => $channex->propertyId()],
                'response' => $summary,
            ]);

            $this->info(sprintf(
                'Kontrolluar: %d · në rregull: %d · probleme: %d · kandidatë manualë: %d',
                $summary['checked'],
                $summary['clean'],
                $summary['issues'],
                $summary['manual_candidates'],
            ));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Kontrolli dështoi: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
