<?php

namespace App\Services\Reporting;

use App\Models\FolioItem;
use App\Models\Reservation;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

final class RoomRevenueService
{
    public function __construct(private readonly StayRevenueAllocator $allocator) {}

    /** @return array{total:float,daily:array<string,float>,by_room_type:array<int,array{total:float,daily:array<string,float>}>} */
    public function summary(ReportingPeriod $period): array
    {
        $daily = collect(CarbonPeriod::create($period->from, $period->to))
            ->mapWithKeys(fn ($day) => [$day->toDateString() => 0.0]);
        $byRoomType = [];

        $reservations = Reservation::query()
            ->with([
                'room:id,room_type_id',
                'folioItems:id,reservation_id,pos_order_id,type,amount',
            ])
            ->where('status', '!=', 'cancelled')
            ->whereNull('no_show_at')
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->whereDate('check_out_date', '>', $period->from->toDateString())
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date', 'total_amount']);

        foreach ($reservations as $reservation) {
            $typeId = $reservation->room?->room_type_id;
            $netRoomRevenue = round((float) $reservation->total_amount * $this->discountFactor($reservation), 2);
            foreach ($this->allocator->allocate(
                $reservation->check_in_date,
                $reservation->check_out_date,
                $netRoomRevenue,
                $period,
            ) as $date => $amount) {
                $daily->put($date, round((float) $daily->get($date, 0) + $amount, 2));
                if ($typeId) {
                    $this->addToRoomType($byRoomType, (int) $typeId, $date, $amount);
                }
            }
        }

        foreach ($byRoomType as &$row) {
            $row['total'] = round((float) array_sum($row['daily']), 2);
        }
        unset($row);

        return [
            'total' => round((float) $daily->sum(), 2),
            'daily' => $daily->all(),
            'by_room_type' => $byRoomType,
        ];
    }

    /** @param array<int> $reservationIds @return array<int,float> */
    public function discountFactors(array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }

        return Reservation::query()
            ->with('folioItems:id,reservation_id,pos_order_id,type,amount')
            ->whereIn('id', $reservationIds)
            ->get(['id', 'total_amount'])
            ->mapWithKeys(fn (Reservation $reservation) => [
                $reservation->id => $this->discountFactor($reservation),
            ])->all();
    }

    private function discountFactor(Reservation $reservation): float
    {
        /** @var Collection<int,FolioItem> $items */
        $items = $reservation->folioItems;
        $charges = (float) $reservation->total_amount
            + (float) $items->whereNotIn('type', ['discount', 'room'])->sum('amount');
        $discounts = (float) $items
            ->where('type', 'discount')
            ->whereNull('pos_order_id')
            ->sum('amount');

        return $charges > 0
            ? max(0, min(1, ($charges - $discounts) / $charges))
            : 1.0;
    }

    /** @param array<int,array{total:float,daily:array<string,float>}> $byRoomType */
    private function addToRoomType(array &$byRoomType, int $typeId, string $date, float $amount): void
    {
        $byRoomType[$typeId] ??= ['total' => 0.0, 'daily' => []];
        $byRoomType[$typeId]['daily'][$date] = round(
            ($byRoomType[$typeId]['daily'][$date] ?? 0.0) + $amount,
            2,
        );
    }
}
