<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per autopilot price change — the owner's audit + 1-tap revert. */
class PricingAutopilotLog extends Model
{
    protected $fillable = [
        'room_type_id',
        'date',
        'old_price',
        'effective_old_price',
        'new_price',
        'reverted_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'old_price' => 'decimal:2',
            'effective_old_price' => 'decimal:2',
            'new_price' => 'decimal:2',
            'reverted_at' => 'datetime',
        ];
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
