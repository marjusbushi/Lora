<?php

namespace App\Models;

class Amenity extends TenantModel
{
    protected $fillable = ['name', 'sort_order'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
