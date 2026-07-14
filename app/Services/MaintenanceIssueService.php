<?php

namespace App\Services;

use App\Models\MaintenanceIssue;
use App\Models\MaintenanceIssueEvent;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceIssueService
{
    /** @param array<string, mixed> $data */
    public function report(array $data, User $reporter): MaintenanceIssue
    {
        return DB::transaction(function () use ($data, $reporter) {
            $room = isset($data['room_id']) ? Room::find($data['room_id']) : null;
            $blockRoom = $room && (bool) ($data['block_room'] ?? false);
            $priority = $data['priority'] ?? 'medium';

            $issue = MaintenanceIssue::create([
                'room_id' => $room?->id,
                'cleaning_task_id' => $data['cleaning_task_id'] ?? null,
                'reported_by' => $reporter->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? 'other',
                'kind' => $data['kind'] ?? 'corrective',
                'priority' => $priority,
                'status' => 'reported',
                'source' => $data['source'] ?? 'manual',
                'asset_name' => $data['asset_name'] ?? null,
                'asset_code' => $data['asset_code'] ?? null,
                'room_blocked' => $blockRoom,
                'previous_room_status' => $blockRoom ? $room->status : null,
                'recurrence_days' => $data['recurrence_days'] ?? null,
                'scheduled_for' => $data['scheduled_for'] ?? null,
                'due_at' => $data['due_at'] ?? $this->dueAt($priority),
            ]);

            if ($blockRoom && $room->status !== 'maintenance') {
                $room->update(['status' => 'maintenance']);
            }

            $this->event($issue, $reporter, 'reported', null, 'reported', $data['event_note'] ?? null);

            return $issue;
        });
    }

    public function assign(MaintenanceIssue $issue, User $assignee, User $actor): MaintenanceIssue
    {
        return DB::transaction(function () use ($issue, $assignee, $actor) {
            $from = $issue->status;
            $to = in_array($from, ['reported', 'assigned'], true) ? 'assigned' : $from;
            $issue->update(['assigned_to' => $assignee->id, 'status' => $to]);
            $this->event($issue, $actor, 'assigned', $from, $to, $assignee->name);

            return $issue;
        });
    }

    public function transition(MaintenanceIssue $issue, string $to, User $actor, ?string $note = null): MaintenanceIssue
    {
        $allowed = [
            'reported' => ['assigned'],
            'assigned' => ['in_progress'],
            'in_progress' => ['resolved'],
            'resolved' => ['in_progress', 'verified'],
            'verified' => ['closed'],
            'closed' => [],
        ];

        $from = $issue->status;
        if (! in_array($to, $allowed[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "Kalimi nga {$from} në {$to} nuk lejohet.",
            ]);
        }

        if ($to === 'assigned' && ! $issue->assigned_to) {
            throw ValidationException::withMessages(['status' => 'Cakto teknikun para se të vazhdosh.']);
        }

        return DB::transaction(function () use ($issue, $from, $to, $actor, $note) {
            $data = ['status' => $to];
            if ($to === 'in_progress' && ! $issue->started_at) {
                $data['started_at'] = now();
            }
            if ($to === 'resolved') {
                $data['resolved_at'] = now();
            }
            if ($to === 'verified') {
                $data['verified_by'] = $actor->id;
                $data['verified_at'] = now();
            }
            if ($to === 'closed') {
                $data['closed_at'] = now();
            }

            $issue->update($data);

            if (in_array($to, ['verified', 'closed'], true)) {
                $this->restoreRoom($issue);
            }

            $this->event($issue, $actor, 'status_changed', $from, $to, $note);

            if ($to === 'closed' && $issue->kind === 'preventive' && $issue->recurrence_days && $issue->scheduled_for) {
                $nextDate = $issue->scheduled_for->copy()->addDays($issue->recurrence_days);
                $this->report([
                    'room_id' => $issue->room_id,
                    'title' => $issue->title,
                    'description' => $issue->description,
                    'category' => $issue->category,
                    'kind' => 'preventive',
                    'priority' => $issue->priority,
                    'source' => 'scheduled',
                    'asset_name' => $issue->asset_name,
                    'asset_code' => $issue->asset_code,
                    'recurrence_days' => $issue->recurrence_days,
                    'scheduled_for' => $nextDate,
                    'due_at' => $nextDate,
                    'event_note' => 'Krijuar automatikisht nga plani periodik.',
                ], $actor);
            }

            return $issue;
        });
    }

    public function setRoomBlocked(MaintenanceIssue $issue, bool $blocked, User $actor): MaintenanceIssue
    {
        if (! $issue->room) {
            throw ValidationException::withMessages(['room_blocked' => 'Ky problem nuk është lidhur me një dhomë.']);
        }

        return DB::transaction(function () use ($issue, $blocked, $actor) {
            if ($blocked && ! $issue->room_blocked) {
                $issue->update([
                    'room_blocked' => true,
                    'previous_room_status' => $issue->room->status,
                ]);
                $issue->room->update(['status' => 'maintenance']);
            } elseif (! $blocked && $issue->room_blocked) {
                $this->restoreRoom($issue);
            }

            $this->event(
                $issue,
                $actor,
                $blocked ? 'room_blocked' : 'room_released',
                $issue->status,
                $issue->status,
                null,
            );

            return $issue;
        });
    }

    private function restoreRoom(MaintenanceIssue $issue): void
    {
        if (! $issue->room_blocked || ! $issue->room || $issue->room->status !== 'maintenance') {
            return;
        }

        $restore = in_array($issue->previous_room_status, ['available', 'occupied', 'cleaning'], true)
            ? $issue->previous_room_status
            : 'available';

        $issue->room->update(['status' => $restore]);
        $issue->update(['room_blocked' => false]);
    }

    private function event(
        MaintenanceIssue $issue,
        User $actor,
        string $type,
        ?string $from,
        ?string $to,
        ?string $note,
    ): void {
        MaintenanceIssueEvent::create([
            'maintenance_issue_id' => $issue->id,
            'user_id' => $actor->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
        ]);
    }

    private function dueAt(string $priority): Carbon
    {
        return match ($priority) {
            'critical' => now()->addHour(),
            'high' => now()->addHours(4),
            'low' => now()->addHours(72),
            default => now()->addHours(24),
        };
    }
}
