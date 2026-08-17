<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mesazh i ri mysafiri në inbox (task #343) — transmetohet MENJËHERË (jo në
 * queue: vonesa e queue-poll do ta vriste "live"-in) në kanalin privat të
 * tenant-it. Payload minimal — faqja bën partial reload vetë; përmbajtja
 * s'udhëton kurrë nëpër socket (më pak sipërfaqe rrjedhjeje).
 */
class MessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $tenantId,
        public int $threadId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->tenantId.'.messages')];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        return ['thread_id' => $this->threadId];
    }
}
