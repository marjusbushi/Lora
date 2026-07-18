<?php

namespace App\Services\Reporting;

use App\Models\Budget;
use Carbon\CarbonImmutable;

final class BudgetTargetService
{
    /** @return array{has_budget:bool,revenue_target:float|null,adr_target:float|null,occupancy_target:float|null,revpar_target:float|null} */
    public function forPeriod(ReportingPeriod $period): array
    {
        $months = [];

        for ($month = $period->from->startOfMonth(); $month->lessThanOrEqualTo($period->to); $month = $month->addMonth()) {
            $months[] = $month->format('Y-m');
        }

        $budgets = Budget::query()->whereIn('period', $months)->get()->keyBy('period');
        $revenueTarget = 0.0;
        $weightedTargets = ['adr_target' => 0.0, 'occupancy_target' => 0.0, 'revpar_target' => 0.0];
        $targetDays = ['adr_target' => 0, 'occupancy_target' => 0, 'revpar_target' => 0];

        foreach ($months as $monthKey) {
            $budget = $budgets->get($monthKey);
            if (! $budget) {
                continue;
            }

            $monthStart = CarbonImmutable::createFromFormat('Y-m-d', $monthKey.'-01')->startOfMonth();
            $overlapFrom = $period->from->max($monthStart);
            $overlapTo = $period->to->min($monthStart->endOfMonth());
            $overlapDays = $overlapFrom->diffInDays($overlapTo) + 1;
            $monthDays = $monthStart->daysInMonth;

            if ($budget->revenue_target !== null) {
                $revenueTarget += (float) $budget->revenue_target * $overlapDays / $monthDays;
            }

            foreach (array_keys($weightedTargets) as $key) {
                if ($budget->{$key} !== null) {
                    $weightedTargets[$key] += (float) $budget->{$key} * $overlapDays;
                    $targetDays[$key] += $overlapDays;
                }
            }
        }

        return [
            'has_budget' => $budgets->isNotEmpty(),
            'revenue_target' => $budgets->contains(fn (Budget $budget) => $budget->revenue_target !== null) ? round($revenueTarget, 2) : null,
            'adr_target' => $targetDays['adr_target'] ? round($weightedTargets['adr_target'] / $targetDays['adr_target'], 2) : null,
            'occupancy_target' => $targetDays['occupancy_target'] ? round($weightedTargets['occupancy_target'] / $targetDays['occupancy_target'], 1) : null,
            'revpar_target' => $targetDays['revpar_target'] ? round($weightedTargets['revpar_target'] / $targetDays['revpar_target'], 2) : null,
        ];
    }
}
