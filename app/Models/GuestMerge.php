<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestMerge extends TenantModel
{
    protected $fillable = [
        'primary_guest_id',
        'secondary_guest_id',
        'merged_by',
        'field_sources',
        'secondary_snapshot',
        'moved_counts',
        'suggestion_source',
    ];

    protected function casts(): array
    {
        return [
            'field_sources' => 'array',
            'secondary_snapshot' => 'array',
            'moved_counts' => 'array',
        ];
    }

    public function primaryGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'primary_guest_id')->withTrashed();
    }

    public function secondaryGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'secondary_guest_id')->withTrashed();
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
