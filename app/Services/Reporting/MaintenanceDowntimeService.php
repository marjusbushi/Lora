<?php

namespace App\Services\Reporting;

use App\Models\MaintenanceIssue;
use App\Models\MaintenanceIssueEvent;
use App\Models\Room;
use Carbon\CarbonImmutable;

final class MaintenanceDowntimeService
{
    /**
     * Builds immutable downtime intervals from maintenance events. Historical
     * reporting must not depend on the issue's current room_blocked flag.
     *
     * @param  iterable<int|string>  $roomIds
     * @return array<int, array{room_id:int,starts_at:string,ends_at:?string}>
     */
    public function forRooms(iterable $roomIds, ReportingPeriod $period): array
    {
        $ids = collect($roomIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $issues = MaintenanceIssue::withTrashed()
            ->with(['events' => fn ($query) => $query->oldest('created_at')->oldest('id')])
            ->whereIn('room_id', $ids)
            ->where('created_at', '<=', $period->to->endOfDay())
            ->get();
        $intervals = [];

        foreach ($issues as $issue) {
            $events = $issue->events;
            $hasExplicitBlock = $events->contains(fn (MaintenanceIssueEvent $event) => $event->type === 'room_blocked');
            $blockedAt = null;

            if (! $hasExplicitBlock && ($issue->previous_room_status !== null || $issue->room_blocked)) {
                $blockedAt = CarbonImmutable::parse($issue->created_at);
            }

            foreach ($events as $event) {
                $eventAt = CarbonImmutable::parse($event->created_at);

                if ($event->type === 'room_blocked' && $blockedAt === null) {
                    $blockedAt = $eventAt;

                    continue;
                }

                $releasesRoom = $event->type === 'room_released'
                    || ($event->type === 'status_changed' && in_array($event->to_status, ['verified', 'closed'], true));

                if ($releasesRoom && $blockedAt !== null) {
                    $this->pushInterval($intervals, $issue->room_id, $blockedAt, $eventAt);
                    $blockedAt = null;
                }
            }

            if ($blockedAt !== null) {
                $verifiedAt = $issue->verified_at ? CarbonImmutable::parse($issue->verified_at) : null;
                $this->pushInterval($intervals, $issue->room_id, $blockedAt, $verifiedAt);
            }
        }

        // Legacy/manual maintenance without an issue is only a current-state
        // fallback. It must never rewrite a completed historical period.
        if ($period->to->greaterThanOrEqualTo(today())) {
            $openRoomIds = collect($intervals)
                ->filter(fn (array $interval) => $interval['ends_at'] === null)
                ->pluck('room_id');

            Room::query()
                ->whereIn('id', $ids)
                ->where('status', 'maintenance')
                ->whereNotIn('id', $openRoomIds)
                ->get(['id', 'updated_at'])
                ->each(function (Room $room) use (&$intervals, $period) {
                    $updatedAt = CarbonImmutable::parse($room->updated_at);
                    $startsAt = $updatedAt->greaterThan($period->from) ? $updatedAt : $period->from;

                    if ($startsAt->lessThanOrEqualTo($period->to->endOfDay())) {
                        $this->pushInterval($intervals, $room->id, $startsAt, null);
                    }
                });
        }

        return $intervals;
    }

    /** @param array<int, array{room_id:int,starts_at:string,ends_at:?string}> $intervals */
    private function pushInterval(
        array &$intervals,
        int $roomId,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
    ): void {
        if ($endsAt && $endsAt->lessThanOrEqualTo($startsAt)) {
            return;
        }

        $intervals[] = [
            'room_id' => $roomId,
            'starts_at' => $startsAt->toDateTimeString(),
            'ends_at' => $endsAt?->toDateTimeString(),
        ];
    }
}
