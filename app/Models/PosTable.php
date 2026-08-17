<?php

namespace App\Models;

class PosTable extends TenantModel
{
    protected $fillable = ['number', 'name', 'area', 'outlet_id', 'seats', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(PosOutlet::class, 'outlet_id');
    }
}
