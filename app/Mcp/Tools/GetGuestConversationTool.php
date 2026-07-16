<?php

namespace App\Mcp\Tools;

use App\Models\MessageThread;
use App\Services\TenantBillingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetGuestConversationTool extends LoraTool
{
    protected string $name = 'get-guest-conversation';

    protected string $description = 'Read the latest messages in one guest conversation together with its linked reservation context.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->integer()->minimum(1)->required(),
            'limit' => $schema->integer()->minimum(1)->maximum(50)->default(30),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->user($request, 'view_reservations');
        abort_unless($this->enabled('messages_enabled') && $this->moduleEnabled(TenantBillingService::CHANNEL_MANAGER), 403);
        $data = $request->validate([
            'thread_id' => ['required', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $thread = MessageThread::with(['reservation.guest', 'reservation.room.roomType'])->findOrFail($data['thread_id']);
        $messages = $thread->messages()->latest('sent_at')->limit($data['limit'] ?? 30)->get()->reverse()->values();

        return Response::structured([
            'thread' => [
                'id' => $thread->id,
                'channel' => $thread->channel,
                'status' => $thread->status,
                'guest_name' => $thread->guest_name,
                'reservation_id' => $thread->reservation_id,
                'stay' => $thread->reservation ? [
                    'check_in' => $thread->reservation->check_in_date?->format('Y-m-d'),
                    'check_out' => $thread->reservation->check_out_date?->format('Y-m-d'),
                    'status' => $thread->reservation->status,
                    'room' => $thread->reservation->room?->room_number,
                    'room_type' => $thread->reservation->room?->roomType?->name,
                ] : null,
                'messages' => $messages->map(fn ($message) => [
                    'sender' => $message->sender,
                    'body' => $message->body,
                    'sent_at' => $message->sent_at?->toIso8601String(),
                    'has_attachment' => $message->has_attachment,
                ])->all(),
            ],
        ]);
    }
}
