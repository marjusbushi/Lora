<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageThread extends TenantModel
{
    protected $fillable = [
        'channex_thread_id', 'whatsapp_jid', 'channel', 'channex_booking_id', 'reservation_id',
        'guest_name', 'status', 'last_message_preview', 'last_message_at', 'unread_count',
        'ai_suggestion', 'ai_suggested_at', 'ai_unanswered_question',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
            'ai_suggested_at' => 'datetime',
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
