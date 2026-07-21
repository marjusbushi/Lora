<?php

namespace App\Mcp\Tools;

use App\Models\AiActionProposal;
use App\Models\RoomType;
use App\Services\AiPriceGuardrails;
use App\Services\CommercialPriceRounding;
use App\Services\MarketRates;
use App\Services\PricingCurrency;
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

    protected string $description = 'Create a reviewable price proposal from either the deterministic Lora engine or bounded ChatGPT recommendations based on get-pricing-calendar. This never changes prices and always requires explicit confirmation.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'room_type_id' => $schema->integer()->min(1)->required(),
            'date_from' => $schema->string()->description('YYYY-MM-DD')->required(),
            'date_to' => $schema->string()->description('YYYY-MM-DD')->required(),
            'proposal_source' => $schema->string()->enum(['lora_engine', 'chatgpt'])->default('lora_engine'),
            'recommendations' => $schema->array()->max(35)->items($schema->object([
                'date' => $schema->string()->description('YYYY-MM-DD returned by get-pricing-calendar.')->required(),
                'price' => $schema->number()->min(0.01)->description('ChatGPT alternative price inside the returned guardrails.')->required(),
                'reason' => $schema->string()->min(3)->max(400)->description('Short evidence-based explanation; never invent unavailable market data.')->required(),
                'confidence' => $schema->integer()->min(0)->max(100)->description('Confidence percentage based only on available hotel and market evidence.')->required(),
            ])->withoutAdditionalProperties()),
            'idempotency_key' => $schema->string()->min(8)->max(120)->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user($request, 'view_settings');
        abort_unless($this->enabled('pricing_enabled')
            && $this->moduleEnabled(TenantBillingService::SMART_PRICING), 403);
        $data = $request->validate([
            'room_type_id' => ['required', 'integer', 'min:1'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'proposal_source' => ['nullable', 'in:lora_engine,chatgpt'],
            'recommendations' => ['nullable', 'array', 'max:35', 'required_if:proposal_source,chatgpt'],
            'recommendations.*.date' => ['required', 'date', 'distinct'],
            'recommendations.*.price' => ['required', 'numeric', 'gt:0'],
            'recommendations.*.reason' => ['required', 'string', 'min:3', 'max:400'],
            'recommendations.*.confidence' => ['required', 'integer', 'between:0,100'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);
        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'])->startOfDay();
        abort_if($from->isPast() || $from->diffInDays($to) > 35, 422, 'Use a future range of maximum 35 days.');
        $type = RoomType::findOrFail($data['room_type_id']);
        $source = $data['proposal_source'] ?? 'lora_engine';
        abort_if($source === 'chatgpt' && ! $this->enabled('ai_price_recommendations_enabled'), 403, 'ChatGPT price recommendations are disabled for this hotel.');

        $engineDays = collect(PricingEngine::forRange($type, $from, $to))
            ->filter(fn ($day) => ! $day['is_past'])->values();
        $market = MarketRates::summaryForRange($from, $to);
        $rulesVersion = PricingRulesVersion::current();

        if ($source === 'chatgpt') {
            $byDate = $engineDays->keyBy('date');
            $days = collect($data['recommendations'] ?? [])->map(function (array $recommendation) use ($byDate, $type, $market) {
                $day = $byDate->get($recommendation['date']);
                abort_unless($day, 422, "No live pricing context exists for {$recommendation['date']}.");
                $requestedPrice = round((float) $recommendation['price'], 2);
                $limits = AiPriceGuardrails::limits($type, $day);
                abort_unless(AiPriceGuardrails::accepts($type, $day, $requestedPrice), 422,
                    "ChatGPT price for {$recommendation['date']} must be between {$limits['min']} and {$limits['max']}.");
                $rounding = CommercialPriceRounding::apply(
                    $requestedPrice,
                    (float) $limits['min'],
                    (float) $limits['max'],
                );
                $price = $rounding['after'];
                abort_unless(AiPriceGuardrails::accepts($type, $day, $price), 422,
                    "Rounded ChatGPT price for {$recommendation['date']} must stay between {$limits['min']} and {$limits['max']}.");

                return [
                    'date' => $day['date'],
                    'price' => $price,
                    'calculated_price' => $requestedPrice,
                    'rounding' => $rounding,
                    'source' => 'chatgpt',
                    'current_price' => round((float) $day['current_price'], 2),
                    'lora_engine_price' => round((float) $day['suggested_price'], 2),
                    'market' => $market[$day['date']] ?? null,
                    'reason' => trim($recommendation['reason']),
                    'confidence' => (int) $recommendation['confidence'],
                    'guardrails' => $limits,
                ];
            })->values()->all();
        } else {
            $days = $engineDays
                ->filter(fn ($day) => $day['actionable'])
                ->map(fn ($day) => ['date' => $day['date'], 'price' => round((float) $day['suggested_price'], 2)])
                ->values()->all();
        }
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
                    'proposal_source' => $source,
                    'rules_version' => $rulesVersion,
                    'engine_fingerprint' => AiPriceGuardrails::fingerprint($engineDays->all(), $market, $rulesVersion),
                    'currency' => PricingCurrency::code(),
                    'max_ai_deviation_pct' => AiPriceGuardrails::maxDeviationPct(),
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
