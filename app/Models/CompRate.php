<?php

namespace App\Models;

class CompRate extends TenantModel
{
    protected $fillable = [
        'competitor',
        'date',
        'price',
        'currency',
        'source',
        'snapshot_date',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'snapshot_date' => 'date:Y-m-d',
        'price' => 'decimal:2',
    ];
}
