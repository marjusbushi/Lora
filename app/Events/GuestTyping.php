<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mysafiri po shkruan / po regjistron zë në WhatsApp (task #344) — ngjarje
 * KALIMTARE: transmetohet menjëherë, s'preket kurrë DB (as unread, as radhë).
 * 'paused' udhëton gjithashtu që faqja ta fshijë treguesin pa pritur timeout.
 */
class GuestTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $tenantId,
        public int $threadId,
        public string $state, // composing | recording | paused
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->tenantId.'.messages')];
    }

    public function broadcastAs(): string
    {
        return 'guest.typing';
    }

    public function broadcastWith(): array
    {
        return ['thread_id' => $this->threadId, 'state' => $this->state];
    }
}
