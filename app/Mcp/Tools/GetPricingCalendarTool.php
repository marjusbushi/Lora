<?php

namespace App\Mcp\Tools;

use App\Models\RoomType;
use App\Services\AiPriceGuardrails;
use App\Services\CommercialPriceRounding;
use App\Services\MarketRates;
use App\Services\PricingCurrency;
use App\Services\PricingRulesVersion;
use App\Services\SmartPricing;
use App\Services\TenantBillingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetPricingCalendarTool extends LoraTool
{
    protected string $name = 'get-pricing-calendar';

    protected string $description = 'Get live prices, Lora deterministic recommendations, occupancy, factors, market comparison when available, and safe bounds for an independent ChatGPT price recommendation, maximum 35 days.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'room_type_id' => $schema->integer()->min(1)->required(),
            'date_from' => $schema->string()->description('YYYY-MM-DD')->required(),
            'date_to' => $schema->string()->description('YYYY-MM-DD')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->user($request, 'view_settings');
        abort_unless($this->enabled('pricing_enabled') && $this->moduleEnabled(TenantBillingService::SMART_PRICING), 403);
        $data = $request->validate([
            'room_type_id' => ['required', 'integer', 'min:1'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);
        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'])->startOfDay();
        abort_if($from->diffInDays($to) > 35, 422, 'Maximum pricing window is 35 days.');
        $type = RoomType::findOrFail($data['room_type_id']);
        $market = MarketRates::summaryForRange($from, $to);
        $aiRecommendations = $this->enabled('ai_price_recommendations_enabled');
        $days = collect(SmartPricing::calendar($type, $from, $to))->map(function ($day) use ($type, $market, $aiRecommendations) {
            $result = collect($day)->only([
                'date', 'current_price', 'calculated_price', 'guarded_price', 'suggested_price', 'adjustment_pct', 'occupancy_pct',
                'occupancy_type_pct', 'occupancy_property_pct', 'booked', 'total', 'days_until',
                'actionable', 'has_override', 'factors', 'events', 'clamped', 'rounding', 'quiet_reason',
            ])->all();
            $result['lora_engine_price'] = (float) $day['suggested_price'];
            $result['market'] = $market[$day['date']] ?? null;
            $result['chatgpt_recommendation'] = [
                'allowed' => $aiRecommendations,
                'guardrails' => AiPriceGuardrails::limits($type, $day),
                'instruction' => $aiRecommendations
                    ? 'You may recommend a different price using all returned evidence, but keep it inside guardrails and explain the strongest reasons and confidence. Lora will apply the hotel commercial-rounding policy to the final proposal.'
                    : 'Independent ChatGPT price recommendations are disabled for this hotel.',
            ];

            return $result;
        })->values()->all();

        return Response::structured([
            'room_type' => ['id' => $type->id, 'name' => $type->name],
            'currency' => PricingCurrency::code(),
            'commercial_rounding' => CommercialPriceRounding::policy(),
            'rules_version' => PricingRulesVersion::current(),
            'comparison' => [
                'current' => 'Live selling price',
                'lora_engine' => 'Deterministic Lora recommendation',
                'chatgpt' => 'Independent bounded recommendation created from this evidence',
                'market' => 'Latest competitor snapshot when configured; never invented when absent',
            ],
            'days' => $days,
        ]);
    }
}
