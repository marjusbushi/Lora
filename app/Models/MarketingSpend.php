<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class MarketingSpend extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'channel',
        'amount',
        'spend_date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spend_date' => 'date',
        ];
    }
}
