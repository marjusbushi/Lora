<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Season extends Model
{
    protected $fillable = ['name', 'start_date', 'end_date', 'priority'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'priority' => 'integer',
        ];
    }

    public function rates()
    {
        return $this->hasMany(SeasonRate::class);
    }
}
