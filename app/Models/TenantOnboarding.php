<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantOnboarding extends Model
{
    protected $fillable = [
        'tenant_id', 'assigned_to', 'status', 'progress', 'due_date', 'steps', 'notes',
        'completed_at', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withoutGlobalScopes();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TenantOnboardingDocument::class);
    }
}
