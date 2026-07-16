<?php

namespace App\Mcp\Tools;

use App\Models\AiActionProposal;
use App\Models\MessageThread;
use App\Services\TenantBillingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class PrepareGuestReplyTool extends LoraTool
{
    protected string $name = 'prepare-guest-reply';

    protected string $description = 'Prepare a guest reply for human review. This does not send anything. Use execute-approved-action only after the user explicitly confirms the preview.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->integer()->min(1)->required(),
            'body' => $schema->string()->min(1)->max(2000)->required(),
            'idempotency_key' => $schema->string()->min(8)->max(120)->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user($request, 'view_reservations');
        abort_unless($this->enabled('messages_enabled')
            && $this->enabled('guest_reply_enabled')
            && $this->moduleEnabled(TenantBillingService::CHANNEL_MANAGER), 403);
        $data = $request->validate([
            'thread_id' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);
        $thread = MessageThread::findOrFail($data['thread_id']);
        $proposal = AiActionProposal::firstOrCreate(
            ['user_id' => $user->id, 'idempotency_key' => $data['idempotency_key']],
            [
                'type' => 'guest_reply',
                'payload' => ['thread_id' => $thread->id, 'guest_name' => $thread->guest_name, 'body' => $data['body']],
                'status' => 'pending',
                'expires_at' => now()->addMinutes(15),
            ],
        );

        return Response::structured([
            'proposal_id' => $proposal->id,
            'status' => $proposal->status,
            'expires_at' => $proposal->expires_at->toIso8601String(),
            'preview' => $proposal->payload,
            'requires_explicit_confirmation' => true,
        ]);
    }
}
