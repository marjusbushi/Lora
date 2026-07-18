<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;

final class StayRevenueAllocator
{
    /**
     * Allocates an accommodation total across stay nights, then clips it to the
     * requested period. Integer cents keep the full-stay total deterministic.
     *
     * @return array<string, float> keyed by stay date (Y-m-d)
     */
    public function allocate(
        string|CarbonImmutable $checkIn,
        string|CarbonImmutable $checkOut,
        float|string $total,
        ReportingPeriod $period,
    ): array {
        $from = $checkIn instanceof CarbonImmutable ? $checkIn->startOfDay() : CarbonImmutable::parse($checkIn)->startOfDay();
        $to = $checkOut instanceof CarbonImmutable ? $checkOut->startOfDay() : CarbonImmutable::parse($checkOut)->startOfDay();
        $nights = $from->diffInDays($to, false);

        if ($nights <= 0) {
            return [];
        }

        $totalCents = (int) round((float) $total * 100);
        $baseCents = intdiv($totalCents, $nights);
        $remainder = $totalCents - ($baseCents * $nights);
        $allocation = [];

        for ($index = 0; $index < $nights; $index++) {
            $date = $from->addDays($index);
            $sign = $remainder <=> 0;
            $extraCent = $index < abs($remainder) ? $sign : 0;

            if ($period->contains($date)) {
                $allocation[$date->toDateString()] = (float) (($baseCents + $extraCent) / 100);
            }
        }

        return $allocation;
    }
}
