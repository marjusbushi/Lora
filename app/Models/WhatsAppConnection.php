<?php

namespace App\Models;

/**
 * Gjendja e lidhjes WhatsApp QR-lite e hotelit (një rresht per-tenant).
 * Burimi i së vërtetës për statusin është ura Node — ky rresht është pasqyra
 * e fundit e raportuar (ngjarjet 'status' nga ura e përditësojnë).
 */
class WhatsAppConnection extends TenantModel
{
    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_PAIRING = 'pairing';

    public const STATUS_CONNECTED = 'connected';

    protected $table = 'whatsapp_connections';

    protected $fillable = [
        'status', 'phone_number', 'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'last_event_at' => 'datetime',
        ];
    }
}
