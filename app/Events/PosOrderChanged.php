<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Porosi POS e krijuar/ndryshuar/fshirë (task #346) — emetohet nga
 * PosOrderObserver PAS commit-it, pra mbulon çdo burim me një pikë të vetme:
 * kasën (PosController), shërbimin në tavolinë (rounds), dhe porosinë publike
 * QR nga çadrat e plazhit. Payload minimal — ekranet bëjnë partial reload.
 */
class PosOrderChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $tenantId,
        public int $orderId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->tenantId.'.pos')];
    }

    public function broadcastAs(): string
    {
        return 'pos.order.changed';
    }

    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId];
    }
}
