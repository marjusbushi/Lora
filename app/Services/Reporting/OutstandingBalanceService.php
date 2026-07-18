<?php

namespace App\Services\Reporting;

use App\Models\FolioItem;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

final class OutstandingBalanceService
{
    /** @return array{count:int,total:float} */
    public function summary(): array
    {
        $stays = Reservation::query()
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->get(['id', 'total_amount']);

        if ($stays->isEmpty()) {
            return ['count' => 0, 'total' => 0.0];
        }

        $ids = $stays->pluck('id')->all();
        $folio = FolioItem::query()
            ->whereIn('reservation_id', $ids)
            ->select(
                'reservation_id',
                DB::raw("SUM(CASE WHEN type NOT IN ('discount', 'room') THEN amount ELSE 0 END) as charges"),
                DB::raw("SUM(CASE WHEN type = 'discount' THEN amount ELSE 0 END) as discounts"),
            )
            ->groupBy('reservation_id')
            ->get()
            ->keyBy('reservation_id');
        $payments = Payment::query()
            ->whereIn('reservation_id', $ids)
            ->notVoided()
            ->select('reservation_id', DB::raw('SUM(amount) as paid'))
            ->groupBy('reservation_id')
            ->get()
            ->keyBy('reservation_id');

        $balances = $stays->map(function (Reservation $reservation) use ($folio, $payments) {
            $items = $folio->get($reservation->id);
            $paid = $payments->get($reservation->id);

            return round(
                (float) $reservation->total_amount
                + (float) ($items?->charges ?? 0)
                - (float) ($items?->discounts ?? 0)
                - (float) ($paid?->paid ?? 0),
                2,
            );
        })->filter(fn (float $balance) => $balance > 0.009);

        return ['count' => $balances->count(), 'total' => round((float) $balances->sum(), 2)];
    }
}
