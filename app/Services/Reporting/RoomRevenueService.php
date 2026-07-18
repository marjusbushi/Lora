<?php

namespace App\Services\Reporting;

use App\Models\FolioItem;
use App\Models\Reservation;
use Carbon\CarbonPeriod;

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
            ->with('room:id,room_type_id')
            ->where('status', '!=', 'cancelled')
            ->whereNull('no_show_at')
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->whereDate('check_out_date', '>', $period->from->toDateString())
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date', 'total_amount']);

        foreach ($reservations as $reservation) {
            $typeId = $reservation->room?->room_type_id;
            foreach ($this->allocator->allocate(
                $reservation->check_in_date,
                $reservation->check_out_date,
                $reservation->total_amount,
                $period,
            ) as $date => $amount) {
                $daily->put($date, round((float) $daily->get($date, 0) + $amount, 2));
                if ($typeId) {
                    $this->addToRoomType($byRoomType, (int) $typeId, $date, $amount);
                }
            }
        }

        $discounts = FolioItem::query()
            ->with('reservation.room:id,room_type_id')
            ->where('type', 'discount')
            ->whereNull('pos_order_id')
            ->whereHas('reservation', fn ($query) => $query
                ->where('status', '!=', 'cancelled')
                ->whereNull('no_show_at'))
            ->whereBetween('charge_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->get(['id', 'reservation_id', 'amount', 'charge_date']);

        foreach ($discounts as $discount) {
            $date = $discount->charge_date?->toDateString();
            if (! $date || ! $daily->has($date)) {
                continue;
            }

            $amount = -abs((float) $discount->amount);
            $daily->put($date, round((float) $daily->get($date) + $amount, 2));
            $typeId = $discount->reservation?->room?->room_type_id;
            if ($typeId) {
                $this->addToRoomType($byRoomType, (int) $typeId, $date, $amount);
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
