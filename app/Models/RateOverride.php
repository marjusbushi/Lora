<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price for ONE date + room type, overriding the seasonal/base price for that night.
 * Created when the owner accepts a Smart Pricing suggestion (or sets a date price by hand).
 */
class RateOverride extends TenantModel
{
    protected $fillable = [
        'date',
        'room_type_id',
        'price',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'price' => 'decimal:2',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
