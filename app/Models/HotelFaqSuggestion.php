<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sugjerim FAQ nga cikli i mësimit të Lora AI: pyetja që Lora s'e dinte +
 * përgjigjja që dha stafi në bisedë. Pronari e ruan te FAQ me një klik ose
 * e hedh poshtë. Per-tenant (TenantModel).
 */
class HotelFaqSuggestion extends TenantModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SAVED = 'saved';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'message_thread_id', 'question', 'suggested_answer', 'status',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'message_thread_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
