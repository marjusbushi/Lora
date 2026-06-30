<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelMapping extends Model
{
    protected $fillable = [
        'channel',
        'room_type_id',
        'beds24_prop_id',
        'beds24_room_id',
        'channex_property_id',
        'channex_room_type_id',
        'channex_rate_plan_id',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
