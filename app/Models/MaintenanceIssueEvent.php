<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceIssueEvent extends TenantModel
{
    protected $fillable = [
        'maintenance_issue_id', 'user_id', 'type', 'from_status', 'to_status', 'note',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(MaintenanceIssue::class, 'maintenance_issue_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
