<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestDocument extends TenantModel
{
    protected $fillable = [
        'guest_id',
        'type',
        'original_name',
        'path',
        'mime',
        'size',
        'uploaded_by',
    ];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
