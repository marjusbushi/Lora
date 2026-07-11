<?php

namespace App\Models;

class SeasonRate extends TenantModel
{
    protected $fillable = ['season_id', 'room_type_id', 'price'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
