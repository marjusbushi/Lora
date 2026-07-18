<?php

namespace App\Services\Reporting;

use App\Models\MaintenanceIssue;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class RecurringMaintenanceIssueService
{
    /** @return array{period:array,lookback_from:string,summary:array,categories:array,daily:array,groups:array} */
    public function summary(ReportingPeriod $period): array
    {
        $asOf = $period->to->endOfDay()->min(CarbonImmutable::now());
        $lookbackFrom = $period->from->subYear()->startOfDay();
        $issues = MaintenanceIssue::withTrashed()
            ->with([
                'room:id,room_number',
                'events' => fn ($query) => $query->oldest('created_at')->oldest('id'),
            ])
            ->whereBetween('created_at', [$lookbackFrom, $asOf])
            ->get([
                'id', 'room_id', 'title', 'category', 'priority', 'status',
                'asset_name', 'asset_code', 'created_at',
            ]);
        $periodIssues = $issues->filter(fn (MaintenanceIssue $issue) => $this->inPeriod($issue, $period, $asOf));

        $groups = $issues->groupBy(fn (MaintenanceIssue $issue) => $this->signature($issue))
            ->map(fn (Collection $rows) => $this->group($rows->sortBy('created_at')->values(), $period, $asOf))
            ->filter(fn (?array $group) => $group !== null)
            ->sortByDesc(fn (array $group) => [$group['repeat_occurrences'], $group['last_at']])
            ->values();
        $repeatDates = $groups->flatMap(fn (array $group) => $group['repeat_dates']);
        $intervals = $groups->flatMap(fn (array $group) => $group['interval_days']);

        return [
            'period' => $period->toArray(),
            'lookback_from' => $lookbackFrom->toDateString(),
            'summary' => [
                'recurring_groups' => $groups->count(),
                'repeat_occurrences' => $repeatDates->count(),
                'repeat_rate' => $periodIssues->isEmpty() ? 0.0 : round($repeatDates->count() / $periodIssues->count() * 100, 1),
                'avg_interval_days' => $intervals->isEmpty() ? 0.0 : round((float) $intervals->avg(), 1),
                'affected_rooms' => $groups->flatMap(fn (array $group) => $group['room_ids'])->unique()->count(),
                'open_issues' => $groups->sum('open_count'),
            ],
            'categories' => $groups->groupBy('category')->map(fn (Collection $rows, string $key) => [
                'key' => $key,
                'value' => $rows->sum('repeat_occurrences'),
            ])->sortByDesc('value')->values()->all(),
            'daily' => collect(CarbonPeriod::create($period->from, $period->to))->map(function ($day) use ($repeatDates) {
                $date = $day->toDateString();

                return ['date' => $date, 'value' => $repeatDates->filter(fn (string $repeatDate) => $repeatDate === $date)->count()];
            })->all(),
            'groups' => $groups->take(50)->map(fn (array $group) => collect($group)->except(['repeat_dates', 'interval_days', 'room_ids'])->all())->all(),
        ];
    }

    private function group(Collection $issues, ReportingPeriod $period, CarbonImmutable $asOf): ?array
    {
        $periodRows = $issues->filter(fn (MaintenanceIssue $issue) => $this->inPeriod($issue, $period, $asOf));
        if ($periodRows->isEmpty() || $issues->count() < 2) {
            return null;
        }

        $repeatRows = $periodRows->filter(function (MaintenanceIssue $issue) use ($issues) {
            return $issues->search(fn (MaintenanceIssue $candidate) => $candidate->id === $issue->id) > 0;
        });
        if ($repeatRows->isEmpty()) {
            return null;
        }

        $latest = $issues->last();
        $intervals = $issues->sliding(2)->map(fn (Collection $pair) => CarbonImmutable::parse($pair->first()->created_at)->diffInDays(CarbonImmutable::parse($pair->last()->created_at)));
        $rooms = $periodRows->pluck('room_id')->filter()->unique()->values();

        return [
            'key' => $this->signature($latest),
            'label' => $latest->asset_name ?: $latest->title,
            'asset_code' => $latest->asset_code,
            'room' => $latest->room?->room_number,
            'category' => $latest->category ?: 'other',
            'priority' => $latest->priority,
            'period_occurrences' => $periodRows->count(),
            'repeat_occurrences' => $repeatRows->count(),
            'total_occurrences' => $issues->count(),
            'avg_interval_days' => $intervals->isEmpty() ? 0.0 : round((float) $intervals->avg(), 1),
            'open_count' => $issues->filter(fn (MaintenanceIssue $issue) => ! in_array($this->statusAt($issue, $asOf), ['verified', 'closed'], true))->count(),
            'last_at' => $latest->created_at?->toDateTimeString(),
            'latest_issue_id' => $latest->id,
            'repeat_dates' => $repeatRows->map(fn (MaintenanceIssue $issue) => $issue->created_at->toDateString())->values()->all(),
            'interval_days' => $intervals->values()->all(),
            'room_ids' => $rooms->all(),
        ];
    }

    private function signature(MaintenanceIssue $issue): string
    {
        if ($this->normalize($issue->asset_code) !== '') {
            return 'asset:'.$this->normalize($issue->asset_code);
        }

        $subject = $this->normalize($issue->asset_name) ?: $this->normalize($issue->title);

        return implode(':', ['location', $issue->room_id ?: 'general', $this->normalize($issue->category) ?: 'other', $subject]);
    }

    private function normalize(?string $value): string
    {
        return Str::lower(Str::squish((string) $value));
    }

    private function inPeriod(MaintenanceIssue $issue, ReportingPeriod $period, CarbonImmutable $asOf): bool
    {
        return $issue->created_at
            && $issue->created_at->betweenIncluded($period->from->startOfDay(), $asOf);
    }

    private function statusAt(MaintenanceIssue $issue, CarbonImmutable $asOf): string
    {
        return $issue->events
            ->filter(fn ($event) => $event->to_status && CarbonImmutable::parse($event->created_at)->lessThanOrEqualTo($asOf))
            ->last()?->to_status ?: $issue->status;
    }
}
