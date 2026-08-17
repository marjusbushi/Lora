<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Rezervim i krijuar/ndryshuar/fshirë (task #345) — emetohet nga
 * ReservationObserver PAS commit-it, pra mbulon ÇDO burim me një pikë të
 * vetme: recepsionin, webin publik, importuesin Channex, komandat. Payload
 * minimal — faqet bëjnë partial reload vetë; të dhënat s'udhëtojnë në socket.
 */
class ReservationChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $tenantId,
        public int $reservationId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->tenantId.'.reservations')];
    }

    public function broadcastAs(): string
    {
        return 'reservation.changed';
    }

    public function broadcastWith(): array
    {
        return ['reservation_id' => $this->reservationId];
    }
}
