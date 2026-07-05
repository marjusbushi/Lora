<?php

namespace App\Http\Controllers;

use App\Models\CleaningTask;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CleaningTaskController extends Controller
{
    public function index(Request $request): Response
    {
        $query = CleaningTask::select(
            'id', 'room_id', 'assigned_to', 'started_by', 'inspected_by', 'type', 'status',
            'priority', 'notes', 'issue_reported', 'checklist', 'started_at', 'completed_at',
            'inspected_at', 'created_at'
        )
            ->with([
                'room:id,room_number,floor',
                'room.roomType:id,name',
                'assignedUser:id,name',
                'inspectedBy:id,name',
            ])
            // Daily-archived (inspected) tasks drop off the board — kept in the DB for records.
            ->whereNull('archived_at')
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 WHEN 'completed' THEN 2 WHEN 'inspected' THEN 3 ELSE 4 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END");

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('floor')) {
            $query->whereHas('room', fn($q) => $q->where('floor', $request->floor));
        }

        $housekeepers = User::role('housekeeping')->select('id', 'name')->get();

        return Inertia::render('Housekeeping/Index', [
            'tasks' => $query->paginate(20),
            'housekeepers' => $housekeepers,
            'filters' => $request->only('status', 'floor'),
            'stats' => [
                'pending' => CleaningTask::where('status', 'pending')->count(),
                'in_progress' => CleaningTask::where('status', 'in_progress')->count(),
                'completed' => CleaningTask::whereDate('completed_at', today())->where('status', 'completed')->count(),
                'urgent' => CleaningTask::where('priority', 'urgent')->whereIn('status', ['pending', 'in_progress'])->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'room_id' => ['required', 'exists:rooms,id'],
            'type' => ['required', 'in:checkout_clean,stayover_clean,deep_clean,inspection'],
            'priority' => ['sometimes', 'in:normal,urgent'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        CleaningTask::create([
            'room_id' => $request->room_id,
            'type' => $request->type,
            'priority' => $request->priority ?? 'normal',
            'assigned_to' => $request->assigned_to,
            'notes' => $request->notes,
        ]);

        return back()->with('success', 'Detyra u krijua.');
    }

    public function updateStatus(Request $request, CleaningTask $cleaningTask): RedirectResponse
    {
        $request->validate([
            'status' => ['required', 'in:pending,in_progress,completed,inspected'],
        ]);

        $newStatus = $request->status;
        $data = ['status' => $newStatus];

        if ($newStatus === 'in_progress') {
            // Snapshot the checklist template the FIRST time a task starts, so later
            // template edits in Settings never rewrite an in-flight task's list.
            if (empty($cleaningTask->checklist)) {
                $data['checklist'] = collect(CleaningTask::templateFor($cleaningTask->type))
                    ->map(fn ($label) => ['label' => $label, 'done' => false, 'done_at' => null])
                    ->values()->all();
            }
            if (! $cleaningTask->started_at) {
                $data['started_at'] = now();
                $data['started_by'] = $request->user()->id;
            }
        }

        if ($newStatus === 'completed') {
            // ENFORCE server-side: every checklist item must be done before completion
            // (not just a disabled button). An empty/absent list completes immediately.
            $hasUndone = collect($cleaningTask->checklist ?? [])->contains(fn ($item) => empty($item['done']));
            if ($hasUndone) {
                return back()->with('error', 'Përfundo të gjitha pikat e listës para se ta mbyllësh dhomën.');
            }
            $data['completed_at'] = now();
        }

        if ($newStatus === 'inspected') {
            $data['inspected_by'] = $request->user()->id;
            $data['inspected_at'] = now();
        }

        // Completing or inspecting frees the room (room availability is NOT gated on
        // inspection — it frees at 'completed', per product decision).
        if ($newStatus === 'completed' || $newStatus === 'inspected') {
            DB::transaction(function () use ($cleaningTask, $data) {
                $cleaningTask->update($data);
                // A task can outlive its room (a deleted room leaves the task behind) —
                // guard the null so finishing such a task never crashes the board (mistake #93).
                $room = $cleaningTask->room;
                if ($room && $room->status === 'cleaning') {
                    $room->update(['status' => 'available']);
                }
            });
        } else {
            $cleaningTask->update($data);
        }

        return back()->with('success', 'Statusi u perditesua.');
    }

    /**
     * Persist the per-task checklist state (tap-to-toggle from the clean view).
     * Labels stay server-owned (from the snapshot); only the done flags are taken
     * from the client, by index — a tampered label cannot desync enforce-complete.
     */
    public function updateChecklist(Request $request, CleaningTask $cleaningTask): RedirectResponse
    {
        $this->authorizeTask($request, $cleaningTask);

        $validated = $request->validate([
            'items' => ['present', 'array'],
            'items.*.done' => ['required', 'boolean'],
            'items.*.label' => ['sometimes', 'string', 'max:200'],
        ]);

        $clientDone = collect($validated['items'])->pluck('done')->all();

        $checklist = collect($cleaningTask->checklist ?? [])->map(function ($item, $idx) use ($clientDone) {
            $done = (bool) ($clientDone[$idx] ?? ($item['done'] ?? false));
            return [
                'label' => $item['label'],
                'done' => $done,
                'done_at' => $done ? ($item['done_at'] ?? now()->toDateTimeString()) : null,
            ];
        })->values()->all();

        $cleaningTask->update(['checklist' => $checklist]);

        return back()->with('success', 'Lista u ruajt.');
    }

    /**
     * Full-screen, phone-first cleaning view: room header, live timer (from started_at),
     * and the checklist. Read-only render — starting the task happens in updateStatus.
     */
    public function clean(Request $request, CleaningTask $cleaningTask): Response
    {
        $this->authorizeTask($request, $cleaningTask);

        $cleaningTask->load(['room:id,room_number,floor', 'room.roomType:id,name']);

        return Inertia::render('Housekeeping/Clean', [
            'task' => [
                'id' => $cleaningTask->id,
                'status' => $cleaningTask->status,
                'type' => $cleaningTask->type,
                'priority' => $cleaningTask->priority,
                'checklist' => $cleaningTask->checklist ?? [],
                'started_at' => optional($cleaningTask->started_at)->toIso8601String(),
                'issue_reported' => $cleaningTask->issue_reported,
                'room' => $cleaningTask->room ? [
                    'room_number' => $cleaningTask->room->room_number,
                    'floor' => $cleaningTask->room->floor,
                    'room_type' => $cleaningTask->room->roomType?->name,
                ] : null,
            ],
        ]);
    }

    public function assign(Request $request, CleaningTask $cleaningTask): RedirectResponse
    {
        $request->validate([
            'assigned_to' => ['required', 'exists:users,id'],
        ]);

        $cleaningTask->update(['assigned_to' => $request->assigned_to]);

        return back()->with('success', 'Detyra u caktua.');
    }

    public function reportIssue(Request $request, CleaningTask $cleaningTask): RedirectResponse
    {
        $request->validate([
            'issue_reported' => ['required', 'string', 'max:1000'],
        ]);

        $cleaningTask->update([
            'issue_reported' => $request->issue_reported,
        ]);

        // Set room to maintenance if serious (room may be gone — guard the null).
        if ($request->boolean('set_maintenance')) {
            $cleaningTask->room?->update(['status' => 'maintenance']);
        }

        return back()->with('success', 'Problemi u raportua.');
    }

    /**
     * A housekeeper may only open/modify their OWN task (or an unassigned one they can
     * pick up); supervisors (manager/admin — hold delete_housekeeping) may touch any.
     * Prevents IDOR on the clean view + checklist endpoints via a guessable task id.
     */
    private function authorizeTask(Request $request, CleaningTask $task): void
    {
        $user = $request->user();

        if ($user->can('delete_housekeeping')) {
            return;
        }

        if ($task->assigned_to === null || $task->assigned_to === $user->id) {
            return;
        }

        abort(403, 'Kjo detyrë i është caktuar një pastruesi tjetër.');
    }
}
