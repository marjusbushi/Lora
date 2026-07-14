<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSubscription extends Model
{
    protected $fillable = [
        'tenant_id',
        'status',
        'billing_cycle',
        'currency',
        'annual_discount_percent',
        'starts_at',
        'trial_ends_at',
        'current_period_ends_at',
        'cancels_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'annual_discount_percent' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancels_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
