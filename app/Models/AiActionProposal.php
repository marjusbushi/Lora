<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AiActionProposal extends TenantModel
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'type', 'payload', 'status', 'idempotency_key', 'expires_at', 'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }
}
