<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Budget extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'period',
        'revenue_target',
        'adr_target',
        'occupancy_target',
        'revpar_target',
    ];

    protected function casts(): array
    {
        return [
            'revenue_target' => 'decimal:2',
            'adr_target' => 'decimal:2',
            'occupancy_target' => 'decimal:2',
            'revpar_target' => 'decimal:2',
        ];
    }
}
