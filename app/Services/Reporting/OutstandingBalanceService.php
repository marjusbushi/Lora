<?php

namespace App\Services\Reporting;

use App\Models\FolioItem;
use App\Models\Payment;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OutstandingBalanceService
{
    /** @return array{count:int,total:float} */
    public function summary(): array
    {
        $summary = $this->analytics()['summary'];

        return ['count' => $summary['count'], 'total' => $summary['total']];
    }

    /** @return array{as_of:string,summary:array,buckets:array,statuses:array,rows:array} */
    public function analytics(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();
        $stays = Reservation::query()
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->with(['room:id,room_number', 'guest:id,first_name,last_name,phone'])
            ->get([
                'id', 'room_id', 'guest_id', 'status', 'channel', 'check_in_date',
                'check_out_date', 'total_amount_base',
            ]);

        if ($stays->isEmpty()) {
            return $this->emptyAnalytics($asOf);
        }

        $ids = $stays->pluck('id')->all();
        $folio = FolioItem::query()
            ->whereIn('reservation_id', $ids)
            ->select(
                'reservation_id',
                DB::raw("SUM(CASE WHEN type NOT IN ('discount', 'room') THEN amount_base ELSE 0 END) as charges"),
                DB::raw("SUM(CASE WHEN type = 'discount' THEN amount_base ELSE 0 END) as discounts"),
            )
            ->groupBy('reservation_id')
            ->get()
            ->keyBy('reservation_id');
        $payments = Payment::query()
            ->whereIn('reservation_id', $ids)
            ->notVoided()
            ->select(
                'reservation_id',
                DB::raw("SUM(CASE WHEN COALESCE(type, 'payment') IN ('payment', 'deposit') THEN amount_base WHEN type = 'refund' THEN -ABS(amount_base) ELSE 0 END) as paid"),
                DB::raw("SUM(CASE WHEN type = 'writeoff' THEN amount_base ELSE 0 END) as written_off"),
            )
            ->groupBy('reservation_id')
            ->get()
            ->keyBy('reservation_id');

        $rows = $stays->map(function (Reservation $reservation) use ($folio, $payments, $asOf) {
            $items = $folio->get($reservation->id);
            $gross = round(max(0,
                (float) $reservation->total_amount_base
                + (float) ($items?->charges ?? 0)
                - (float) ($items?->discounts ?? 0),
            ), 2);
            $paid = round((float) ($payments->get($reservation->id)?->paid ?? 0), 2);
            $writtenOff = round((float) ($payments->get($reservation->id)?->written_off ?? 0), 2);
            $balance = round($gross - $paid - $writtenOff, 2);
            $dueDate = CarbonImmutable::parse($reservation->check_out_date);
            $daysOverdue = $dueDate->lessThan($asOf) ? (int) $dueDate->diffInDays($asOf) : 0;
            $bucket = $this->bucketFor($daysOverdue);

            return [
                'id' => $reservation->id,
                'guest' => trim("{$reservation->guest?->first_name} {$reservation->guest?->last_name}") ?: 'Mysafir',
                'phone' => $reservation->guest?->phone,
                'room' => $reservation->room?->room_number,
                'status' => $reservation->status,
                'channel' => Reservation::normalizeChannel($reservation->channel),
                'check_in' => $reservation->check_in_date?->toDateString(),
                'check_out' => $reservation->check_out_date?->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'days_overdue' => $daysOverdue,
                'bucket' => $bucket,
                'gross' => $gross,
                'paid' => $paid,
                'written_off' => $writtenOff,
                'balance' => $balance,
            ];
        });
        $openRows = $rows->filter(fn (array $row) => $row['balance'] > 0.009)
            ->sortBy([
                fn (array $a, array $b) => $b['days_overdue'] <=> $a['days_overdue'],
                fn (array $a, array $b) => $b['balance'] <=> $a['balance'],
            ])->values();

        $total = round((float) $openRows->sum('balance'), 2);
        $overdue = $openRows->where('days_overdue', '>', 0);
        $critical = $openRows->where('days_overdue', '>', 30);
        $gross = round((float) $rows->sum('gross'), 2);
        $paid = round((float) $rows->sum('paid'), 2);

        return [
            'as_of' => $asOf->toDateString(),
            'summary' => [
                'count' => $openRows->count(),
                'total' => $total,
                'gross' => $gross,
                'paid' => $paid,
                'collection_rate' => $gross > 0
                    ? round(max(0, min(100, $paid / $gross * 100)), 1)
                    : 0.0,
                'overdue_count' => $overdue->count(),
                'overdue_total' => round((float) $overdue->sum('balance'), 2),
                'critical_count' => $critical->count(),
                'critical_total' => round((float) $critical->sum('balance'), 2),
                'average_balance' => $openRows->isNotEmpty() ? round($total / $openRows->count(), 2) : 0.0,
            ],
            'buckets' => $this->bucketSummary($openRows, $total),
            'statuses' => $openRows->groupBy('status')->map(fn (Collection $statusRows, string $status) => [
                'status' => $status,
                'count' => $statusRows->count(),
                'amount' => round((float) $statusRows->sum('balance'), 2),
            ])->sortByDesc('amount')->values()->all(),
            'rows' => $openRows->all(),
        ];
    }

    private function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue === 0 => 'not_due',
            $daysOverdue <= 7 => '1_7',
            $daysOverdue <= 30 => '8_30',
            $daysOverdue <= 60 => '31_60',
            default => '61_plus',
        };
    }

    /** @return array<int,array{key:string,count:int,amount:float,share:float}> */
    private function bucketSummary(Collection $rows, float $total): array
    {
        return collect(['not_due', '1_7', '8_30', '31_60', '61_plus'])
            ->map(function (string $key) use ($rows, $total) {
                $bucketRows = $rows->where('bucket', $key);
                $amount = round((float) $bucketRows->sum('balance'), 2);

                return [
                    'key' => $key,
                    'count' => $bucketRows->count(),
                    'amount' => $amount,
                    'share' => $total > 0 ? round($amount / $total * 100, 1) : 0.0,
                ];
            })->all();
    }

    private function emptyAnalytics(CarbonImmutable $asOf): array
    {
        return [
            'as_of' => $asOf->toDateString(),
            'summary' => [
                'count' => 0,
                'total' => 0.0,
                'gross' => 0.0,
                'paid' => 0.0,
                'collection_rate' => 0.0,
                'overdue_count' => 0,
                'overdue_total' => 0.0,
                'critical_count' => 0,
                'critical_total' => 0.0,
                'average_balance' => 0.0,
            ],
            'buckets' => $this->bucketSummary(collect(), 0.0),
            'statuses' => [],
            'rows' => [],
        ];
    }
}
