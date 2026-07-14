<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceAttachment;
use App\Models\MaintenanceIssue;
use App\Models\Room;
use App\Models\User;
use App\Services\MaintenanceIssueService;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceController extends Controller
{
    public function __construct(private readonly MaintenanceIssueService $issues) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['reported', 'assigned', 'in_progress', 'resolved', 'verified', 'closed'])],
            'priority' => ['nullable', Rule::in(['critical', 'high', 'medium', 'low'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = MaintenanceIssue::query()
            ->with([
                'room:id,room_number,floor,status',
                'reporter:id,name',
                'assignee:id,name',
                'verifier:id,name',
                'events.user:id,name',
                'attachments:id,tenant_id,maintenance_issue_id,original_name,mime_type,size,created_at',
            ])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $needle = '%'.trim($search).'%';
                $q->where(function ($inner) use ($needle) {
                    $inner->where('title', 'like', $needle)
                        ->orWhere('description', 'like', $needle)
                        ->orWhereHas('room', fn ($room) => $room->where('room_number', 'like', $needle));
                });
            })
            ->orderByRaw("CASE status WHEN 'reported' THEN 0 WHEN 'assigned' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'resolved' THEN 3 WHEN 'verified' THEN 4 ELSE 5 END")
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('due_at')
            ->latest('id');

        $rows = $query->paginate(50)->withQueryString()->through(fn (MaintenanceIssue $issue) => $this->payload($issue));

        return Inertia::render('Maintenance/Design', [
            'issues' => $rows,
            'rooms' => Room::select('id', 'room_number', 'floor', 'status')->orderBy('room_number')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
            'filters' => $filters,
            'stats' => [
                'open' => MaintenanceIssue::whereNotIn('status', ['verified', 'closed'])->count(),
                'urgent' => MaintenanceIssue::where('priority', 'critical')->whereNotIn('status', ['verified', 'closed'])->count(),
                'blocked_rooms' => MaintenanceIssue::where('room_blocked', true)->distinct('room_id')->count('room_id'),
                'preventive_due' => MaintenanceIssue::where('kind', 'preventive')->whereNotIn('status', ['verified', 'closed'])->where('scheduled_for', '<=', now()->addDays(7))->count(),
            ],
            'permissions' => [
                'create' => $request->user()->can('create_maintenance'),
                'update' => $request->user()->can('update_maintenance'),
                'delete' => $request->user()->can('delete_maintenance'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'room_id' => ['nullable', TenantRule::exists('rooms')],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(['electronics', 'climate', 'electrical', 'plumbing', 'furniture', 'safety', 'other'])],
            'kind' => ['required', Rule::in(['corrective', 'preventive'])],
            'priority' => ['required', Rule::in(['critical', 'high', 'medium', 'low'])],
            'asset_name' => ['nullable', 'string', 'max:255'],
            'asset_code' => ['nullable', 'string', 'max:80'],
            'block_room' => ['boolean'],
            'scheduled_for' => ['nullable', 'date'],
            'recurrence_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,application/pdf', 'max:20480'],
        ]);

        $issue = $this->issues->report($data + ['source' => 'manual'], $request->user());
        foreach ($request->file('attachments', []) as $file) {
            $this->saveAttachment($issue, $file, $request->user()->id);
        }

        return redirect()->route('maintenance.index')->with('success', 'Problemi u raportua.');
    }

    public function update(Request $request, MaintenanceIssue $maintenanceIssue): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category' => ['sometimes', Rule::in(['electronics', 'climate', 'electrical', 'plumbing', 'furniture', 'safety', 'other'])],
            'priority' => ['sometimes', Rule::in(['critical', 'high', 'medium', 'low'])],
            'asset_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'asset_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'recurrence_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $maintenanceIssue->update($data);

        return back()->with('success', 'Problemi u përditësua.');
    }

    public function assign(Request $request, MaintenanceIssue $maintenanceIssue): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => ['required', TenantRule::userExists()]]);
        $assignee = User::findOrFail($data['assigned_to']);
        $this->issues->assign($maintenanceIssue, $assignee, $request->user());

        return back()->with('success', 'Tekniku u caktua.');
    }

    public function updateStatus(Request $request, MaintenanceIssue $maintenanceIssue): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['assigned', 'in_progress', 'resolved', 'verified', 'closed'])],
            'note' => ['nullable', 'required_if:status,resolved', 'string', 'max:1000'],
        ]);
        $this->issues->transition($maintenanceIssue, $data['status'], $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Statusi u përditësua.');
    }

    public function updateRoomBlock(Request $request, MaintenanceIssue $maintenanceIssue): RedirectResponse
    {
        $data = $request->validate(['blocked' => ['required', 'boolean']]);
        $this->issues->setRoomBlocked($maintenanceIssue, $data['blocked'], $request->user());

        return back()->with('success', $data['blocked'] ? 'Dhoma u nxor jashtë shërbimit.' : 'Dhoma u rikthye në shërbim.');
    }

    public function storeAttachment(Request $request, MaintenanceIssue $maintenanceIssue): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,application/pdf', 'max:20480'],
        ]);
        $this->saveAttachment($maintenanceIssue, $data['file'], $request->user()->id);

        return back()->with('success', 'Dokumenti u shtua.');
    }

    public function downloadAttachment(MaintenanceAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function destroyAttachment(MaintenanceAttachment $attachment): RedirectResponse
    {
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return back()->with('success', 'Dokumenti u hoq.');
    }

    private function saveAttachment(MaintenanceIssue $issue, $file, int $userId): void
    {
        $tenantId = app(TenantContext::class)->id();
        $name = Str::uuid().'.'.$file->guessExtension();
        $path = $file->storeAs("maintenance/{$tenantId}/{$issue->id}", $name, 'local');

        $issue->attachments()->create([
            'uploaded_by' => $userId,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(MaintenanceIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'description' => $issue->description,
            'category' => $issue->category,
            'kind' => $issue->kind,
            'priority' => $issue->priority,
            'status' => $issue->status,
            'source' => $issue->source,
            'asset_name' => $issue->asset_name,
            'asset_code' => $issue->asset_code,
            'room_blocked' => $issue->room_blocked,
            'room' => $issue->room,
            'reporter' => $issue->reporter,
            'assignee' => $issue->assignee,
            'verifier' => $issue->verifier,
            'due_at' => $issue->due_at?->toIso8601String(),
            'scheduled_for' => $issue->scheduled_for?->toIso8601String(),
            'recurrence_days' => $issue->recurrence_days,
            'created_at' => $issue->created_at?->toIso8601String(),
            'events' => $issue->events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'note' => $event->note,
                'user' => $event->user,
                'created_at' => $event->created_at?->toIso8601String(),
            ]),
            'attachments' => $issue->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'url' => route('maintenance.attachments.show', $attachment),
            ]),
        ];
    }
}
