<?php

namespace App\Services\Reporting;

use App\Models\CleaningTask;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

final class HousekeepingProductivityService
{
    /** @return array{period:array,summary:array,staff:array,types:array,daily:array,tasks:array} */
    public function summary(ReportingPeriod $period): array
    {
        $tasks = CleaningTask::query()
            ->with(['room:id,room_number', 'assignedUser:id,name'])
            ->whereBetween('created_at', [$period->from->startOfDay(), $period->to->endOfDay()])
            ->orderByDesc('created_at')
            ->get([
                'id', 'room_id', 'assigned_to', 'type', 'status', 'priority', 'issue_reported',
                'created_at', 'started_at', 'completed_at', 'inspected_at',
            ]);
        $completed = $tasks->filter(fn (CleaningTask $task) => in_array($task->status, ['completed', 'inspected'], true) && $task->completed_at);
        $started = $tasks->filter(fn (CleaningTask $task) => $task->started_at);
        $inspected = $tasks->filter(fn (CleaningTask $task) => $task->inspected_at || $task->status === 'inspected');

        return [
            'period' => $period->toArray(),
            'summary' => [
                'total' => $tasks->count(),
                'completed' => $completed->count(),
                'pending' => $tasks->whereIn('status', ['pending', 'in_progress'])->count(),
                'completion_rate' => $tasks->isEmpty() ? 0.0 : round($completed->count() / $tasks->count() * 100, 1),
                'avg_clean_minutes' => $this->averageMinutes($completed, 'started_at', 'completed_at'),
                'avg_queue_minutes' => $this->averageMinutes($started, 'created_at', 'started_at'),
                'inspection_rate' => $completed->isEmpty() ? 0.0 : round($inspected->count() / $completed->count() * 100, 1),
                'issues' => $tasks->filter(fn (CleaningTask $task) => filled($task->issue_reported))->count(),
            ],
            'staff' => $this->staff($tasks, $period),
            'types' => $this->types($tasks),
            'daily' => $this->daily($tasks, $period),
            'tasks' => $tasks->take(50)->map(fn (CleaningTask $task) => [
                'id' => $task->id,
                'room' => $task->room?->room_number ?? '—',
                'type' => $task->type,
                'status' => $task->status,
                'priority' => $task->priority,
                'assigned' => $task->assignedUser?->name ?: '—',
                'created_at' => $task->created_at?->toDateTimeString(),
                'queue_minutes' => $this->minutes($task->created_at, $task->started_at),
                'clean_minutes' => $this->minutes($task->started_at, $task->completed_at),
                'has_issue' => filled($task->issue_reported),
            ])->values()->all(),
        ];
    }

    private function staff(Collection $tasks, ReportingPeriod $period): array
    {
        return $tasks->groupBy(fn (CleaningTask $task) => $task->assignedUser?->name ?: 'Unassigned')
            ->map(function (Collection $rows, string $staff) use ($period) {
                $completed = $rows->filter(fn (CleaningTask $task) => in_array($task->status, ['completed', 'inspected'], true) && $task->completed_at);

                return [
                    'staff' => $staff,
                    'assigned' => $rows->count(),
                    'completed' => $completed->count(),
                    'completion_rate' => round($completed->count() / max(1, $rows->count()) * 100, 1),
                    'avg_clean_minutes' => $this->averageMinutes($completed, 'started_at', 'completed_at'),
                    'tasks_per_day' => round($completed->count() / max(1, $period->days()), 1),
                    'issues' => $rows->filter(fn (CleaningTask $task) => filled($task->issue_reported))->count(),
                ];
            })->sortByDesc('completed')->values()->all();
    }

    private function types(Collection $tasks): array
    {
        return $tasks->groupBy('type')->map(function (Collection $rows, string $type) {
            $completed = $rows->filter(fn (CleaningTask $task) => $task->completed_at);

            return [
                'type' => $type,
                'count' => $rows->count(),
                'completed' => $completed->count(),
                'avg_clean_minutes' => $this->averageMinutes($completed, 'started_at', 'completed_at'),
            ];
        })->sortByDesc('count')->values()->all();
    }

    private function daily(Collection $tasks, ReportingPeriod $period): array
    {
        return collect(CarbonPeriod::create($period->from, $period->to))->map(function ($day) use ($tasks) {
            $date = $day->toDateString();
            $rows = $tasks->filter(fn (CleaningTask $task) => $task->created_at?->toDateString() === $date);

            return [
                'date' => $date,
                'assigned' => $rows->count(),
                'completed' => $rows->filter(fn (CleaningTask $task) => in_array($task->status, ['completed', 'inspected'], true))->count(),
            ];
        })->all();
    }

    private function averageMinutes(Collection $rows, string $from, string $to): float
    {
        $values = $rows->map(fn (CleaningTask $task) => $this->minutes($task->{$from}, $task->{$to}))->filter(fn ($value) => $value !== null);

        return $values->isEmpty() ? 0.0 : round((float) $values->avg(), 1);
    }

    private function minutes($from, $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }

        return max(0, CarbonImmutable::parse($from)->diffInMinutes(CarbonImmutable::parse($to)));
    }
}
