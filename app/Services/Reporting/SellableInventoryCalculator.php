<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;

final class SellableInventoryCalculator
{
    /**
     * @param  iterable<array{room_id:int|string, starts_at:string, ends_at?:string|null}>  $blocks
     * @return array{by_date: array<string, array{inventory:int, blocked:int, sellable:int}>, sellable_room_nights:int}
     */
    public function calculate(int $roomCount, iterable $blocks, ReportingPeriod $period): array
    {
        $blockedByDate = [];

        foreach ($blocks as $block) {
            $startsAt = CarbonImmutable::parse($block['starts_at']);
            $endsAt = filled($block['ends_at'] ?? null)
                ? CarbonImmutable::parse($block['ends_at'])
                : $period->to->endOfDay();

            for ($date = $period->from; $date->lessThanOrEqualTo($period->to); $date = $date->addDay()) {
                if ($startsAt->lessThan($date->endOfDay()) && $endsAt->greaterThan($date->startOfDay())) {
                    $blockedByDate[$date->toDateString()][(string) $block['room_id']] = true;
                }
            }
        }

        $byDate = [];
        $total = 0;

        for ($date = $period->from; $date->lessThanOrEqualTo($period->to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $blocked = min($roomCount, count($blockedByDate[$key] ?? []));
            $sellable = max(0, $roomCount - $blocked);
            $byDate[$key] = ['inventory' => $roomCount, 'blocked' => $blocked, 'sellable' => $sellable];
            $total += $sellable;
        }

        return ['by_date' => $byDate, 'sellable_room_nights' => $total];
    }
}
