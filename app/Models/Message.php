<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends TenantModel
{
    public const SENDER_GUEST = 'guest';
    public const SENDER_HOST = 'host';

    protected $fillable = [
        'message_thread_id', 'channex_message_id', 'sender', 'body', 'has_attachment', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'has_attachment' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'message_thread_id');
    }
}
