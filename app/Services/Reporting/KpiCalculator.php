<?php

namespace App\Services\Reporting;

final class KpiCalculator
{
    /** @return array{room_revenue:float,total_revenue:float,occupied_room_nights:int,sellable_room_nights:int,occupancy:float,adr:float,revpar:float,trevpar:float} */
    public function calculate(
        float $roomRevenue,
        float $totalRevenue,
        int $occupiedRoomNights,
        int $sellableRoomNights,
    ): array {
        return [
            'room_revenue' => round($roomRevenue, 2),
            'total_revenue' => round($totalRevenue, 2),
            'occupied_room_nights' => $occupiedRoomNights,
            'sellable_room_nights' => $sellableRoomNights,
            'occupancy' => $sellableRoomNights > 0 ? round($occupiedRoomNights / $sellableRoomNights * 100, 1) : 0.0,
            'adr' => $occupiedRoomNights > 0 ? round($roomRevenue / $occupiedRoomNights, 2) : 0.0,
            'revpar' => $sellableRoomNights > 0 ? round($roomRevenue / $sellableRoomNights, 2) : 0.0,
            'trevpar' => $sellableRoomNights > 0 ? round($totalRevenue / $sellableRoomNights, 2) : 0.0,
        ];
    }

    public function change(float $current, float $comparison): ?float
    {
        if (abs($comparison) < 0.00001) {
            return null;
        }

        return round(($current - $comparison) / abs($comparison) * 100, 1);
    }
}
