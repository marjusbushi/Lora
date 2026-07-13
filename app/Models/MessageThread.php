<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageThread extends TenantModel
{
    protected $fillable = [
        'channex_thread_id', 'channel', 'channex_booking_id', 'reservation_id',
        'guest_name', 'status', 'last_message_preview', 'last_message_at', 'unread_count',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('sent_at')->orderBy('id');
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
