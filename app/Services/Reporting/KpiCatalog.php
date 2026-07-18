<?php

namespace App\Services\Reporting;

final class KpiCatalog
{
    /** @return array<string, array{label:string,unit:string,formula:string}> */
    public static function definitions(): array
    {
        return [
            'room_revenue' => ['label' => 'Room Revenue', 'unit' => 'currency', 'formula' => 'Accommodation revenue allocated by stay date'],
            'occupancy' => ['label' => 'Occupancy', 'unit' => 'percent', 'formula' => 'Occupied room nights / Sellable room nights'],
            'adr' => ['label' => 'ADR', 'unit' => 'currency', 'formula' => 'Room Revenue / Occupied room nights'],
            'revpar' => ['label' => 'RevPAR', 'unit' => 'currency', 'formula' => 'Room Revenue / Sellable room nights'],
            'trevpar' => ['label' => 'TRevPAR', 'unit' => 'currency', 'formula' => 'Total operational revenue / Sellable room nights'],
            'net_revenue' => ['label' => 'Net Revenue', 'unit' => 'currency', 'formula' => 'Gross revenue - commissions - discounts - refunds'],
            'alos' => ['label' => 'ALOS', 'unit' => 'nights', 'formula' => 'Occupied room nights / Stays'],
            'lead_time' => ['label' => 'Lead Time', 'unit' => 'days', 'formula' => 'Check-in date - Booking created date'],
            'pickup' => ['label' => 'Pickup', 'unit' => 'room_nights', 'formula' => 'Current on-books - Reference snapshot on-books'],
            'forecast_accuracy' => ['label' => 'Forecast Accuracy', 'unit' => 'percent', 'formula' => '1 - abs(Forecast - Actual) / Actual'],
        ];
    }
}
