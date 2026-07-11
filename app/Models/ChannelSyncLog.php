<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Prunable;

class ChannelSyncLog extends TenantModel
{
    use Prunable;

    public $timestamps = false; // append-only; only created_at

    /**
     * Retention: this is an append-only audit trail that would otherwise grow
     * forever. `php artisan model:prune` (scheduled) drops rows older than 90
     * days. Callers must log IDs/refs only — never guest PII (name/email/phone)
     * or auth headers — so even un-pruned rows hold nothing sensitive.
     */
    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(90));
    }

    protected $fillable = [
        'channel',
        'direction',
        'action',
        'room_type_id',
        'reservation_id',
        'status',
        'request',
        'response',
        'error',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Best-effort: a logging failure must never break the sync it is recording. */
    public static function record(array $data): void
    {
        try {
            static::create(array_merge(['channel' => 'beds24', 'created_at' => now()], $data));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
