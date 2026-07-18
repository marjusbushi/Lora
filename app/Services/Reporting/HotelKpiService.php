<?php

namespace App\Services\Reporting;

use App\Models\Reservation;
use App\Models\Room;

final class HotelKpiService
{
    public function __construct(
        private readonly StayRevenueAllocator $revenueAllocator,
        private readonly SellableInventoryCalculator $inventoryCalculator,
        private readonly KpiCalculator $kpiCalculator,
        private readonly MaintenanceDowntimeService $maintenanceDowntime,
        private readonly DepartmentRevenueService $departmentRevenue,
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

        $occupiedRoomsByDate = [];
        $commissionByDate = [];

        foreach ($reservations as $reservation) {
            $occupiedDates = $this->revenueAllocator->allocate(
                $reservation->check_in_date,
                $reservation->check_out_date,
                1,
                $period,
            );

            foreach (array_keys($occupiedDates) as $date) {
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

        $roomIds = Room::query()->pluck('id');
        $blocks = $this->maintenanceDowntime->forRooms($roomIds, $period);

        $inventory = $this->inventoryCalculator->calculate(Room::count(), $blocks, $period);
        $departmentRevenue = $this->departmentRevenue->summary($period);
        $departmentDaily = collect($departmentRevenue['daily'])->keyBy('date');
        $daily = [];
        $occupiedRoomNights = 0;
        $commission = 0.0;

        foreach ($inventory['by_date'] as $date => $dayInventory) {
            $dayDepartment = $departmentDaily->get($date, ['rooms' => 0, 'pos' => 0, 'other' => 0, 'total' => 0]);
            $dayOccupied = count($occupiedRoomsByDate[$date] ?? []);
            $daily[$date] = [
                'room_revenue' => round((float) $dayDepartment['rooms'], 2),
                'pos_revenue' => round((float) $dayDepartment['pos'], 2),
                'other_revenue' => round((float) $dayDepartment['other'], 2),
                'total_revenue' => round((float) $dayDepartment['total'], 2),
                'occupied_room_nights' => $dayOccupied,
                'sellable_room_nights' => $dayInventory['sellable'],
            ];
            $occupiedRoomNights += $dayOccupied;
            $commission += (float) ($commissionByDate[$date] ?? 0);
        }

        $roomRevenue = (float) $departmentRevenue['summary']['rooms'];
        $posRevenue = (float) $departmentRevenue['summary']['pos'];
        $otherRevenue = (float) $departmentRevenue['summary']['other'];
        $totalRevenue = (float) $departmentRevenue['summary']['total'];

        return [
            'period' => $period->toArray(),
            'kpis' => array_merge(
                $this->kpiCalculator->calculate(
                    $roomRevenue,
                    $totalRevenue,
                    $occupiedRoomNights,
                    $inventory['sellable_room_nights'],
                ),
                [
                    'pos_revenue' => round($posRevenue, 2),
                    'other_revenue' => round($otherRevenue, 2),
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
            $changes[$key] = $key === 'occupancy'
                ? round((float) $current['kpis'][$key] - (float) $previousPeriod['kpis'][$key], 1)
                : $this->kpiCalculator->change(
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
