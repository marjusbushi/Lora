<?php

namespace App\Models;

class ChannelMapping extends TenantModel
{
    protected $fillable = [
        'channel',
        'room_type_id',
        'beds24_prop_id',
        'beds24_room_id',
        'channex_property_id',
        'channex_room_type_id',
        'channex_rate_plan_id',
        'channex_booking_rate_plan_id',
        'channex_expedia_rate_plan_id',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
