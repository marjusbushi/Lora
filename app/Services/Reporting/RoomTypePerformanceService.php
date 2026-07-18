<?php

namespace App\Services\Reporting;

use App\Models\Reservation;
use App\Models\RoomType;

final class RoomTypePerformanceService
{
    public function __construct(
        private readonly RoomRevenueService $roomRevenue,
        private readonly SellableInventoryCalculator $inventoryCalculator,
        private readonly KpiCalculator $kpiCalculator,
        private readonly MaintenanceDowntimeService $maintenanceDowntime,
    ) {}

    /** @return array{period:array{from:string,to:string},kpis:array,rows:array,daily:array} */
    public function summary(ReportingPeriod $period): array
    {
        $types = RoomType::query()
            ->with(['rooms:id,room_type_id,status'])
            ->orderBy('name')
            ->get(['id', 'name']);
        $roomIds = $types->flatMap(fn (RoomType $type) => $type->rooms->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $reservations = Reservation::query()
            ->whereIn('room_id', $roomIds)
            ->where('status', '!=', 'cancelled')
            ->whereNull('no_show_at')
            ->whereDate('check_in_date', '<=', $period->to->toDateString())
            ->whereDate('check_out_date', '>', $period->from->toDateString())
            ->get(['id', 'room_id', 'check_in_date', 'check_out_date']);

        $blocks = collect($this->maintenanceDowntime->forRooms($roomIds, $period));
        $roomRevenue = $this->roomRevenue->summary($period);

        $rows = $types->map(function (RoomType $type) use ($reservations, $blocks, $period, $roomRevenue) {
            $typeRoomIds = $type->rooms->pluck('id')->map(fn ($id) => (int) $id);
            $typeReservations = $reservations->whereIn('room_id', $typeRoomIds);
            $revenueByDate = $roomRevenue['by_room_type'][$type->id]['daily'] ?? [];
            $occupiedByDate = [];

            foreach ($typeReservations as $reservation) {
                for ($date = $reservation->check_in_date->toImmutable(); $date->lt($reservation->check_out_date); $date = $date->addDay()) {
                    if (! $period->contains($date)) {
                        continue;
                    }

                    $key = $date->toDateString();
                    $occupiedByDate[$key][(string) $reservation->room_id] = true;
                }
            }

            $inventory = $this->inventoryCalculator->calculate(
                $typeRoomIds->count(),
                $blocks->whereIn('room_id', $typeRoomIds)->all(),
                $period,
            );
            $revenue = round((float) array_sum($revenueByDate), 2);
            $occupied = collect($occupiedByDate)->sum(fn (array $rooms) => count($rooms));
            $sellable = $inventory['sellable_room_nights'];
            $kpis = $this->kpiCalculator->calculate($revenue, $revenue, $occupied, $sellable);

            return [
                'type_id' => $type->id,
                'type' => $type->name,
                'rooms_count' => $typeRoomIds->count(),
                'occupied_room_nights' => $occupied,
                'sellable_room_nights' => $sellable,
                'blocked_room_nights' => ($typeRoomIds->count() * $period->days()) - $sellable,
                'room_revenue' => $kpis['room_revenue'],
                'occupancy' => $kpis['occupancy'],
                'adr' => $kpis['adr'],
                'revpar' => $kpis['revpar'],
                'daily_revenue' => $revenueByDate,
                'daily_occupied' => collect($occupiedByDate)->map(fn (array $rooms) => count($rooms))->all(),
                'daily_inventory' => $inventory['by_date'],
            ];
        })->values();

        $daily = [];
        for ($date = $period->from; $date->lessThanOrEqualTo($period->to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $revenue = (float) $rows->sum(fn (array $row) => $row['daily_revenue'][$key] ?? 0);
            $occupied = (int) $rows->sum(fn (array $row) => $row['daily_occupied'][$key] ?? 0);
            $sellable = (int) $rows->sum(fn (array $row) => $row['daily_inventory'][$key]['sellable'] ?? 0);
            $dayKpis = $this->kpiCalculator->calculate($revenue, $revenue, $occupied, $sellable);
            $daily[$key] = [
                'room_revenue' => $dayKpis['room_revenue'],
                'occupied_room_nights' => $occupied,
                'sellable_room_nights' => $sellable,
                'occupancy' => $dayKpis['occupancy'],
                'adr' => $dayKpis['adr'],
                'revpar' => $dayKpis['revpar'],
            ];
        }

        $roomRevenue = (float) $rows->sum('room_revenue');
        $occupied = (int) $rows->sum('occupied_room_nights');
        $sellable = (int) $rows->sum('sellable_room_nights');

        return [
            'period' => $period->toArray(),
            'kpis' => $this->kpiCalculator->calculate($roomRevenue, $roomRevenue, $occupied, $sellable),
            'rows' => $rows->map(fn (array $row) => collect($row)->except(['daily_revenue', 'daily_occupied', 'daily_inventory'])->all())->all(),
            'daily' => $daily,
        ];
    }

    /** @return array{current:array,previous_period:array,previous_year:array,changes:array} */
    public function withComparisons(ReportingPeriod $period): array
    {
        $current = $this->summary($period);
        $previousPeriod = $this->summary($period->previousPeriod());
        $previousYear = $this->summary($period->previousYear());
        $changes = [];

        foreach (['room_revenue', 'occupancy', 'adr', 'revpar'] as $key) {
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
