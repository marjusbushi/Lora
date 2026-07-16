<?php

namespace App\Mcp\Tools;

use App\Models\AiActionProposal;
use App\Models\RoomType;
use App\Services\BaseCurrency;
use App\Services\PricingEngine;
use App\Services\PricingRulesVersion;
use App\Services\TenantBillingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class CreatePriceProposalTool extends LoraTool
{
    protected string $name = 'create-price-proposal';

    protected string $description = 'Create a reviewable proposal from the current deterministic smart-pricing engine. This does not change prices.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'room_type_id' => $schema->integer()->minimum(1)->required(),
            'date_from' => $schema->string()->description('YYYY-MM-DD')->required(),
            'date_to' => $schema->string()->description('YYYY-MM-DD')->required(),
            'idempotency_key' => $schema->string()->minLength(8)->maxLength(120)->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user($request, 'view_settings');
        abort_unless($this->enabled('pricing_enabled')
            && $this->enabled('price_apply_enabled')
            && $this->moduleEnabled(TenantBillingService::SMART_PRICING), 403);
        $data = $request->validate([
            'room_type_id' => ['required', 'integer', 'min:1'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);
        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'])->startOfDay();
        abort_if($from->isPast() || $from->diffInDays($to) > 35, 422, 'Use a future range of maximum 35 days.');
        $type = RoomType::findOrFail($data['room_type_id']);
        $days = collect(PricingEngine::forRange($type, $from, $to))
            ->filter(fn ($day) => $day['actionable'] && ! $day['is_past'])
            ->map(fn ($day) => ['date' => $day['date'], 'price' => round((float) $day['suggested_price'], 2)])
            ->values()->all();
        abort_if($days === [], 422, 'No actionable pricing changes in this range.');

        $proposal = AiActionProposal::firstOrCreate(
            ['user_id' => $user->id, 'idempotency_key' => $data['idempotency_key']],
            [
                'type' => 'pricing_range',
                'payload' => [
                    'room_type_id' => $type->id,
                    'room_type_name' => $type->name,
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                    'rules_version' => PricingRulesVersion::current(),
                    'currency' => BaseCurrency::code(),
                    'days' => $days,
                ],
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
