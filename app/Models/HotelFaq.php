<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * FAQ e hotelit — burimi i vetëm i njohurive nga i cili Lora AI Chat
 * u përgjigjet mysafirëve. Per-tenant (TenantModel).
 */
class HotelFaq extends TenantModel
{
    protected $fillable = [
        'question', 'answer', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
