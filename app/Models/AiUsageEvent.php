<?php

namespace App\Models;

/**
 * Një thirrje AI e matur (task #409) — APPEND-ONLY: shkruhet një herë nga
 * AiUsageRecorder, s'përditësohet kurrë (dëshmi faturimi). Kostoja në
 * MIKRO-USD (integer). Tenant-scoped si çdo model operacional (pa kontekst
 * scope-i mbyllet në tenant_id=0 — fail-closed); raporti i super-adminit
 * deklarohet shprehimisht me withoutGlobalScope('tenant').
 */
class AiUsageEvent extends TenantModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'provider',
        'model',
        'feature',
        'input_tokens',
        'output_tokens',
        'thinking_tokens',
        'cached_tokens',
        'cost_micro_usd',
        'message_thread_id',
        'message_id',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'thinking_tokens' => 'integer',
            'cached_tokens' => 'integer',
            'cost_micro_usd' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
