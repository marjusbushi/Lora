<?php

namespace App\Models;

class RoomTypeImage extends TenantModel
{
    protected $fillable = ['room_type_id', 'path', 'sort_order'];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
