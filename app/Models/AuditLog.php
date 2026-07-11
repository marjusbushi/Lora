<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends TenantModel
{
    public $timestamps = false;

    protected $fillable = [
        'causer_id',
        'source',
        'ip_address',
        'action',
        'subject_type',
        'subject_id',
        'properties',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function causer()
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            $log->causer_id ??= auth()->id();
            $log->source ??= static::inferSource($log);

            if ($log->causer_id && ! $log->ip_address && app()->bound('request')) {
                $log->ip_address = request()->ip();
            }
        });

        // Audit rows are append-only. Corrections are new rows, never edits/deletes.
        static::updating(fn () => throw new LogicException('Audit logs cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit logs cannot be deleted.'));
    }

    /**
     * Record one audit entry for a state/money-changing action.
     * Best-effort: a logging failure must never break the real action.
     */
    public static function record(string $action, ?Model $subject = null, array $properties = [], ?string $source = null): void
    {
        try {
            static::create([
                'causer_id' => auth()->id(),
                'source' => $source ?? static::inferSource(null, $subject),
                'action' => $action,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'properties' => $properties ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function inferSource(?AuditLog $log = null, ?Model $subject = null): string
    {
        if (($log?->causer_id ?? auth()->id()) !== null) {
            return 'staff';
        }

        if ($subject instanceof Reservation) {
            return match ($subject->created_via) {
                Reservation::CREATED_VIA_CHANNEL_MANAGER => 'channex',
                Reservation::CREATED_VIA_WEBSITE => 'website',
                Reservation::CREATED_VIA_IMPORT => 'import',
                default => 'system',
            };
        }

        $route = app()->bound('request') ? request()->route()?->getName() : null;
        if (is_string($route) && str_starts_with($route, 'channex.')) {
            return 'channex';
        }
        if (is_string($route) && str_starts_with($route, 'website.')) {
            return 'website';
        }

        return 'system';
    }
}
