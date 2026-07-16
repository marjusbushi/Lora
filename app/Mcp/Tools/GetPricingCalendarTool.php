<?php

namespace App\Mcp\Tools;

use App\Models\RoomType;
use App\Services\BaseCurrency;
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

    protected string $description = 'Get the current and suggested prices, occupancy, and pricing factors for one room type, maximum 35 days.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'room_type_id' => $schema->integer()->minimum(1)->required(),
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
        $days = collect(SmartPricing::calendar($type, $from, $to))->map(fn ($day) => collect($day)->only([
            'date', 'current_price', 'suggested_price', 'adjustment_pct', 'occupancy_pct', 'booked', 'total',
            'actionable', 'has_override', 'factors', 'events', 'clamped',
        ])->all())->values()->all();

        return Response::structured([
            'room_type' => ['id' => $type->id, 'name' => $type->name],
            'currency' => BaseCurrency::code(),
            'rules_version' => PricingRulesVersion::current(),
            'days' => $days,
        ]);
    }
}
