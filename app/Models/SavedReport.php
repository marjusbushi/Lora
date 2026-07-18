<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedReport extends TenantModel
{
    protected $fillable = [
        'user_id', 'name', 'route_name', 'filters', 'frequency', 'delivery_email',
        'next_delivery_at', 'last_delivered_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'next_delivery_at' => 'datetime',
            'last_delivered_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scheduleNext(): void
    {
        $this->next_delivery_at = match ($this->frequency) {
            'daily' => now()->addDay()->startOfHour(),
            'weekly' => now()->addWeek()->startOfHour(),
            'monthly' => now()->addMonthNoOverflow()->startOfHour(),
            default => null,
        };
    }
}
