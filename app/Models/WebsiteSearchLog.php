<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Prunable;

/**
 * One row per availability search on the public booking form — dates asked,
 * how many rooms came back, and whether the visitor was turned away (denied).
 * Append-only, PII-free. Pruned after 2 years.
 */
class WebsiteSearchLog extends TenantModel
{
    use Prunable;

    public $timestamps = false;

    protected $fillable = [
        'check_in',
        'check_out',
        'room_type_id',
        'results_count',
        'denied',
        'source',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'results_count' => 'integer',
            'denied' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function prunable()
    {
        return static::where('created_at', '<', now()->subYears(2));
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
