<?php

namespace App\Models;

/**
 * One row per (snapshot_date × stay_date × room_type): how many rooms of the
 * type were on the books for that future night, as seen on snapshot_date.
 * Written nightly by `pricing:snapshot` — the raw feed for pickup-pace pricing.
 */
class RoomInventorySnapshot extends TenantModel
{
    protected $fillable = [
        'snapshot_date',
        'stay_date',
        'room_type_id',
        'total_rooms',
        'out_of_order',
        'booked',
        'available',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'stay_date' => 'date',
            'total_rooms' => 'integer',
            'out_of_order' => 'integer',
            'booked' => 'integer',
            'available' => 'integer',
        ];
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
