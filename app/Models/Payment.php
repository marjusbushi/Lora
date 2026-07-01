<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'reservation_id',
        'amount',
        'method',
        'created_by',
        'type',
        'is_voided',
        'pok_order_id',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_voided' => 'boolean',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
