<?php

namespace App\Mcp\Tools;

use App\Models\AiActionProposal;
use App\Services\AiActionExecutor;
use App\Services\TenantBillingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use RuntimeException;
use Throwable;

#[IsDestructive]
#[IsIdempotent]
class ExecuteApprovedActionTool extends LoraTool
{
    protected string $name = 'execute-approved-action';

    protected string $description = 'Execute one reviewed proposal. Call only after the user explicitly confirms the exact preview. The action is idempotent and audited.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->string()->description('Proposal UUID from a prepare/create tool.')->required(),
            'confirm' => $schema->boolean()->description('Must be true only after explicit human confirmation.')->required(),
        ];
    }

    public function handle(Request $request, AiActionExecutor $executor): Response|ResponseFactory
    {
        $user = $this->user($request);
        $data = $request->validate([
            'proposal_id' => ['required', 'uuid'],
            'confirm' => ['required', 'accepted'],
        ]);
        $proposal = AiActionProposal::findOrFail($data['proposal_id']);

        if ($proposal->type === 'pricing_range') {
            $this->user($request, 'view_settings');
            abort_unless($this->enabled('price_apply_enabled') && $this->moduleEnabled(TenantBillingService::SMART_PRICING), 403);
        } else {
            $this->user($request, 'view_reservations');
            abort_unless($this->enabled('guest_reply_enabled') && $this->moduleEnabled(TenantBillingService::CHANNEL_MANAGER), 403);
        }

        try {
            return Response::structured($executor->execute($proposal, $user));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return Response::error('The action failed safely. No unconfirmed change was made.');
        }
    }
}
