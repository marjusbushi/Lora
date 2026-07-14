<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceAttachment extends TenantModel
{
    protected $fillable = [
        'maintenance_issue_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(MaintenanceIssue::class, 'maintenance_issue_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
