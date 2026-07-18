<?php

namespace App\Services\Reporting;

use App\Models\MaintenanceIssue;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

final class MaintenanceSlaReportService
{
    public function __construct(private readonly MaintenanceDowntimeService $downtime) {}

    /** @return array{period:array,summary:array,priorities:array,categories:array,daily:array,issues:array} */
    public function summary(ReportingPeriod $period): array
    {
        $periodEnd = $period->to->endOfDay();
        $asOf = $periodEnd->min(CarbonImmutable::now());
        $issues = MaintenanceIssue::withTrashed()
            ->with([
                'room:id,room_number',
                'assignee:id,name',
                'events' => fn ($query) => $query->oldest('created_at')->oldest('id'),
            ])
            ->whereBetween('created_at', [$period->from->startOfDay(), $asOf])
            ->orderByDesc('created_at')
            ->get([
                'id', 'room_id', 'assigned_to', 'title', 'category', 'priority', 'status',
                'due_at', 'started_at', 'resolved_at', 'verified_at', 'created_at',
            ]);
        $resolved = $issues->filter(fn (MaintenanceIssue $issue) => $this->isResolvedAt($issue, $asOf));
        $slaEligible = $resolved->filter(fn (MaintenanceIssue $issue) => $issue->due_at);
        $slaMet = $slaEligible->filter(fn (MaintenanceIssue $issue) => $this->resolvedAt($issue, $asOf)?->lessThanOrEqualTo($issue->due_at));
        $openAtEnd = $issues->reject(fn (MaintenanceIssue $issue) => $this->isResolvedAt($issue, $asOf));
        $overdue = $openAtEnd->filter(fn (MaintenanceIssue $issue) => $issue->due_at && $issue->due_at->lessThan($asOf));

        $roomIds = Room::query()->pluck('id');
        $intervals = collect($this->downtime->forRooms($roomIds, $period));
        $downtime = $this->downtimeSummary($intervals, $period, $asOf);

        return [
            'period' => $period->toArray(),
            'summary' => [
                'reported' => $issues->count(),
                'resolved' => $resolved->count(),
                'open' => $openAtEnd->count(),
                'overdue' => $overdue->count(),
                'sla_rate' => $slaEligible->isEmpty() ? 0.0 : round($slaMet->count() / $slaEligible->count() * 100, 1),
                'avg_response_hours' => $this->averageResponseHours($issues, $asOf),
                'avg_resolution_hours' => $this->averageResolutionHours($resolved, $asOf),
                'downtime_hours' => $downtime['hours'],
                'affected_rooms' => $downtime['rooms'],
            ],
            'priorities' => $this->grouped($issues, 'priority', $asOf),
            'categories' => $this->grouped($issues, 'category', $asOf),
            'daily' => $this->daily($issues, $period, $asOf),
            'issues' => $issues->take(50)->map(function (MaintenanceIssue $issue) use ($asOf) {
                $resolvedAt = $this->resolvedAt($issue, $asOf);
                $status = $this->statusAt($issue, $asOf);

                return [
                    'id' => $issue->id,
                    'title' => $issue->title,
                    'room' => $issue->room?->room_number,
                    'assignee' => $issue->assignee?->name,
                    'priority' => $issue->priority,
                    'category' => $issue->category,
                    'status' => $status,
                    'created_at' => $issue->created_at?->toDateTimeString(),
                    'response_hours' => $issue->started_at?->lessThanOrEqualTo($asOf) ? $this->hours($issue->created_at, $issue->started_at) : null,
                    'resolution_hours' => $this->hours($issue->created_at, $resolvedAt),
                    'sla' => ! $issue->due_at ? 'not_set' : (! $resolvedAt
                        ? ($issue->due_at->lessThan($asOf) ? 'overdue' : 'open')
                        : ($resolvedAt->lessThanOrEqualTo($issue->due_at) ? 'met' : 'breached')),
                ];
            })->values()->all(),
        ];
    }

    private function grouped(Collection $issues, string $field, CarbonImmutable $periodEnd): array
    {
        return $issues->groupBy($field)->map(function (Collection $rows, string $key) use ($periodEnd) {
            $resolved = $rows->filter(fn (MaintenanceIssue $issue) => $this->isResolvedAt($issue, $periodEnd));
            $slaEligible = $resolved->filter(fn (MaintenanceIssue $issue) => $issue->due_at);

            return [
                'key' => $key,
                'reported' => $rows->count(),
                'resolved' => $resolved->count(),
                'sla_rate' => $slaEligible->isEmpty() ? 0.0 : round($slaEligible->filter(fn (MaintenanceIssue $issue) => $this->resolvedAt($issue, $periodEnd)?->lessThanOrEqualTo($issue->due_at))->count() / $slaEligible->count() * 100, 1),
                'avg_resolution_hours' => $this->averageResolutionHours($resolved, $periodEnd),
            ];
        })->sortByDesc('reported')->values()->all();
    }

    private function daily(Collection $issues, ReportingPeriod $period, CarbonImmutable $asOf): array
    {
        return collect(CarbonPeriod::create($period->from, $period->to))->map(function ($day) use ($issues, $asOf) {
            $date = $day->toDateString();

            return [
                'date' => $date,
                'reported' => $issues->filter(fn (MaintenanceIssue $issue) => $issue->created_at?->toDateString() === $date)->count(),
                'resolved' => $issues->filter(fn (MaintenanceIssue $issue) => $this->resolvedAt($issue, $asOf)?->toDateString() === $date)->count(),
            ];
        })->all();
    }

    /** @return array{hours:float,rooms:int} */
    private function downtimeSummary(Collection $intervals, ReportingPeriod $period, CarbonImmutable $asOf): array
    {
        if ($asOf->lessThanOrEqualTo($period->from->startOfDay())) {
            return ['hours' => 0.0, 'rooms' => 0];
        }

        $clipped = $intervals->map(function (array $interval) use ($period, $asOf) {
            $start = CarbonImmutable::parse($interval['starts_at'])->max($period->from->startOfDay());
            $end = ($interval['ends_at'] ? CarbonImmutable::parse($interval['ends_at']) : $asOf)->min($asOf);

            return $end->greaterThan($start) ? ['room_id' => $interval['room_id'], 'start' => $start, 'end' => $end] : null;
        })->filter()->groupBy('room_id');

        $minutes = $clipped->sum(function (Collection $roomIntervals) {
            $merged = [];
            foreach ($roomIntervals->sortBy('start') as $interval) {
                $last = array_key_last($merged);
                if ($last !== null && $interval['start']->lessThanOrEqualTo($merged[$last]['end'])) {
                    $merged[$last]['end'] = $merged[$last]['end']->max($interval['end']);
                } else {
                    $merged[] = $interval;
                }
            }

            return collect($merged)->sum(fn (array $interval) => $interval['start']->diffInMinutes($interval['end']));
        });

        return ['hours' => round((float) $minutes / 60, 1), 'rooms' => $clipped->count()];
    }

    private function averageResponseHours(Collection $issues, CarbonImmutable $asOf): float
    {
        $values = $issues
            ->filter(fn (MaintenanceIssue $issue) => $issue->started_at?->lessThanOrEqualTo($asOf))
            ->map(fn (MaintenanceIssue $issue) => $this->hours($issue->created_at, $issue->started_at))
            ->filter(fn ($value) => $value !== null);

        return $values->isEmpty() ? 0.0 : round((float) $values->avg(), 1);
    }

    private function averageResolutionHours(Collection $issues, CarbonImmutable $asOf): float
    {
        $values = $issues
            ->map(fn (MaintenanceIssue $issue) => $this->hours($issue->created_at, $this->resolvedAt($issue, $asOf)))
            ->filter(fn ($value) => $value !== null);

        return $values->isEmpty() ? 0.0 : round((float) $values->avg(), 1);
    }

    private function isResolvedAt(MaintenanceIssue $issue, CarbonImmutable $asOf): bool
    {
        return in_array($this->statusAt($issue, $asOf), ['resolved', 'verified', 'closed'], true)
            && $this->resolvedAt($issue, $asOf) !== null;
    }

    private function statusAt(MaintenanceIssue $issue, CarbonImmutable $asOf): string
    {
        $eventStatus = $issue->events
            ->filter(fn ($event) => $event->to_status && CarbonImmutable::parse($event->created_at)->lessThanOrEqualTo($asOf))
            ->last()?->to_status;

        return $eventStatus ?: $issue->status;
    }

    private function resolvedAt(MaintenanceIssue $issue, CarbonImmutable $asOf): ?CarbonImmutable
    {
        if (! in_array($this->statusAt($issue, $asOf), ['resolved', 'verified', 'closed'], true)) {
            return null;
        }

        $event = $issue->events
            ->filter(fn ($event) => $event->to_status === 'resolved' && CarbonImmutable::parse($event->created_at)->lessThanOrEqualTo($asOf))
            ->last();
        if ($event) {
            return CarbonImmutable::parse($event->created_at);
        }

        return $issue->resolved_at?->lessThanOrEqualTo($asOf) ? CarbonImmutable::parse($issue->resolved_at) : null;
    }

    private function hours($from, $to): ?float
    {
        return $from && $to ? round(CarbonImmutable::parse($from)->diffInMinutes(CarbonImmutable::parse($to)) / 60, 1) : null;
    }
}
