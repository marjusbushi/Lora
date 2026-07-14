<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceIssue extends TenantModel
{
    use SoftDeletes;

    protected $fillable = [
        'room_id', 'cleaning_task_id', 'reported_by', 'assigned_to', 'verified_by',
        'title', 'description', 'category', 'kind', 'priority', 'status', 'source',
        'asset_name', 'asset_code', 'room_blocked', 'previous_room_status',
        'recurrence_days', 'scheduled_for', 'due_at', 'started_at', 'resolved_at',
        'verified_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'room_blocked' => 'boolean',
            'scheduled_for' => 'datetime',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function cleaningTask(): BelongsTo
    {
        return $this->belongsTo(CleaningTask::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MaintenanceIssueEvent::class)->oldest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MaintenanceAttachment::class);
    }
}
