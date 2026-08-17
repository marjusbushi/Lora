<?php

namespace App\Observers;

use App\Events\PosOrderChanged;
use App\Models\PosOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Realtime (task #346): njofto ekranet e hapura POS (kasa, tavolinat, paneli
 * i plazhit) PAS commit-it — observer-i mbulon çdo burim shkrimi me një pikë
 * të vetme (kasa, rounds, porosia publike QR). Tenant-i merret nga vetë
 * rreshti — funksionon edhe jashtë HTTP. Dështimi i transmetimit (Reverb
 * offline) s'guxon të prishë kurrë shkrimin e porosisë.
 */
class PosOrderObserver
{
    public function saved(PosOrder $order): void
    {
        $this->broadcastChange($order);
    }

    public function deleted(PosOrder $order): void
    {
        $this->broadcastChange($order);
    }

    private function broadcastChange(PosOrder $order): void
    {
        DB::afterCommit(function () use ($order) {
            try {
                event(new PosOrderChanged((int) $order->tenant_id, (int) $order->id));
            } catch (\Throwable $e) {
                Log::warning('POS order broadcast failed: '.$e->getMessage());
            }
        });
    }
}
