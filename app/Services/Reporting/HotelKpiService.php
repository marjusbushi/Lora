<?php

namespace App\Services\Reporting;

use App\Models\MaintenanceIssue;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;

final class HotelKpiService
{
    public function __construct(
        private readonly StayRevenueAllocator $revenueAllocator,
        private readonly SellableInventoryCalculator $inventoryCalculator,
        private readonly KpiCalculator $kpiCalculator,
    ) {}

    /**
     * @return array{
     *   period: array{from:string,to:string},
     *   kpis: array<string,float|int>,
     *   daily: array<string,array{room_revenue:float,occupied_room_nights:int,sellable_room_nights:int}>
     * }
     */
    public function summary(ReportingPeriod $period): array
    {
        $reservations = Reservation::query()
            ->where('status', '!=', 'cancelled')
            ->whereNull('no_show_at')
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->whereDate('check_out_date', '>', $period->from->toDateString())
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date', 'total_amount', 'commission_amount']);

        $revenueByDate = [];
        $occupiedRoomsByDate = [];
        $commissionByDate = [];

        foreach ($reservations as $reservation) {
            $allocation = $this->revenueAllocator->allocate(
                $reservation->check_in_date,
                $reservation->check_out_date,
                $reservation->total_amount,
                $period,
            );

            foreach ($allocation as $date => $amount) {
                $revenueByDate[$date] = ($revenueByDate[$date] ?? 0.0) + $amount;
                if ($reservation->room_id) {
                    $occupiedRoomsByDate[$date][(string) $reservation->room_id] = true;
                }
            }

            foreach ($this->revenueAllocator->allocate(
                $reservation->check_in_date,
                $reservation->check_out_date,
                $reservation->commission_amount ?? 0,
                $period,
            ) as $date => $amount) {
                $commissionByDate[$date] = ($commissionByDate[$date] ?? 0.0) + $amount;
            }
        }

        $blocks = MaintenanceIssue::query()
            ->where('room_blocked', true)
            ->whereNotNull('room_id')
            ->where('created_at', '<=', $period->to->endOfDay())
            ->where(function ($query) use ($period) {
                $query->whereNull('resolved_at')
                    ->orWhere('resolved_at', '>', $period->from->startOfDay());
            })
            ->get(['room_id', 'created_at', 'resolved_at'])
            ->map(fn (MaintenanceIssue $issue) => [
                'room_id' => $issue->room_id,
                'starts_at' => $issue->created_at->toDateTimeString(),
                'ends_at' => $issue->resolved_at?->toDateTimeString(),
            ])
            ->all();

        $blockedRoomIds = collect($blocks)->pluck('room_id')->map(fn ($id) => (string) $id)->all();
        Room::query()
            ->where('status', 'maintenance')
            ->whereNotIn('id', $blockedRoomIds)
            ->pluck('id')
            ->each(function ($roomId) use (&$blocks, $period) {
                $blocks[] = [
                    'room_id' => $roomId,
                    'starts_at' => $period->from->toDateTimeString(),
                    'ends_at' => null,
                ];
            });

        $inventory = $this->inventoryCalculator->calculate(Room::count(), $blocks, $period);
        $daily = [];
        $roomRevenue = 0.0;
        $occupiedRoomNights = 0;
        $commission = 0.0;

        foreach ($inventory['by_date'] as $date => $dayInventory) {
            $dayRevenue = round((float) ($revenueByDate[$date] ?? 0), 2);
            $dayOccupied = count($occupiedRoomsByDate[$date] ?? []);
            $daily[$date] = [
                'room_revenue' => $dayRevenue,
                'occupied_room_nights' => $dayOccupied,
                'sellable_room_nights' => $dayInventory['sellable'],
            ];
            $roomRevenue += $dayRevenue;
            $occupiedRoomNights += $dayOccupied;
            $commission += (float) ($commissionByDate[$date] ?? 0);
        }

        $posRevenue = (float) PosOrder::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$period->from->startOfDay(), $period->to->endOfDay()])
            ->sum('total_amount');

        return [
            'period' => $period->toArray(),
            'kpis' => array_merge(
                $this->kpiCalculator->calculate(
                    $roomRevenue,
                    $roomRevenue + $posRevenue,
                    $occupiedRoomNights,
                    $inventory['sellable_room_nights'],
                ),
                [
                    'pos_revenue' => round($posRevenue, 2),
                    'commission' => round($commission, 2),
                    'net_room_revenue' => round($roomRevenue - $commission, 2),
                    'reservation_count' => $reservations->count(),
                ],
            ),
            'daily' => $daily,
        ];
    }

    /** @return array{current:array,previous_period:array,previous_year:array,changes:array<string,float|null>} */
    public function withComparisons(ReportingPeriod $period): array
    {
        $current = $this->summary($period);
        $previousPeriod = $this->summary($period->previousPeriod());
        $previousYear = $this->summary($period->previousYear());
        $changes = [];

        foreach (['room_revenue', 'total_revenue', 'occupancy', 'adr', 'revpar', 'trevpar'] as $key) {
            $changes[$key] = $this->kpiCalculator->change(
                (float) $current['kpis'][$key],
                (float) $previousPeriod['kpis'][$key],
            );
        }

        return [
            'current' => $current,
            'previous_period' => $previousPeriod,
            'previous_year' => $previousYear,
            'changes' => $changes,
        ];
    }
}
