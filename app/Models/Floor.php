<?php

namespace App\Models;

class Floor extends TenantModel
{
    protected $fillable = ['number', 'name'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
        ];
    }
}
