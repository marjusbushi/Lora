<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderEvent extends Model
{
    protected $fillable = [
        'tenant_id', 'provider', 'external_id', 'event_type', 'status',
        'attempt_count', 'last_error', 'payload', 'occurred_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
