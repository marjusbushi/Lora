<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * AI Pricing Assistant. Gathers the hotel's own context (room types, current prices, booking
 * pace this year vs last) plus owner-supplied events, asks Claude to reason like a revenue
 * manager for a small Ksamil beach hotel, and returns a structured plan of recommendations
 * (date ranges + per-type suggested prices + plain-Albanian reasoning). Suggest-only.
 */
class AiPricing
{
    public static function configured(): bool
    {
        return app(AnthropicClient::class)->configured();
    }

    /**
     * @param  array<int,string>  $events
     * @return array{summary:?string, recommendations:array<int,array<string,mixed>>, context:array<string,mixed>}
     */
    public static function plan(Carbon $from, Carbon $to, array $events = []): array
    {
        $context = self::context($from, $to);

        $out = app(AnthropicClient::class)->structured(
            self::systemPrompt(),
            self::userPrompt($context, $events, $from, $to),
            self::tool(),
            'submit_pricing_plan',
            3500,
        );

        return [
            'summary' => $out['summary'] ?? null,
            'recommendations' => $out['recommendations'] ?? [],
            'context' => $context,
        ];
    }

    /** @return array<string,mixed> */
    private static function context(Carbon $from, Carbon $to): array
    {
        $types = RoomType::orderBy('name')->get(['id', 'name', 'base_price']);
        $roomsByType = Room::where('status', '!=', 'maintenance')->get(['id', 'room_type_id'])->groupBy('room_type_id');

        $roomTypes = $types->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'rooms' => ($roomsByType[$t->id] ?? collect())->count(),
            'current_price' => RoomPricing::seasonPrice($t, $from),
        ])->values()->all();

        return [
            'hotel' => Setting::get('hotel.name', 'Villa Mucho'),
            'location' => 'Ksamil, Sarandë, Albania (beach destination; guests are mostly Albanian, Italian, Greek, Kosovar)',
            'currency' => Setting::get('financial.default_currency_symbol', '€'),
            'period' => $from->toDateString().' → '.$to->toDateString(),
            'room_types' => $roomTypes,
            'pace' => [
                'reservations_overlapping_period_this_year' => self::overlapping($from, $to),
                'reservations_overlapping_same_period_last_year' => self::overlapping($from->copy()->subYear(), $to->copy()->subYear()),
            ],
        ];
    }

    private static function overlapping(Carbon $from, Carbon $to): int
    {
        return Reservation::whereNotIn('status', ['cancelled'])
            ->whereDate('check_out_date', '>', $from->toDateString())
            ->whereDate('check_in_date', '<', $to->toDateString())
            ->count();
    }

    private static function systemPrompt(): string
    {
        return <<<'TXT'
You are an expert hotel revenue manager for a SMALL beach hotel in Ksamil / Sarandë, Albania
(typically 1–3 rooms per room type, so do NOT over-engineer with occupancy thresholds).
Your job: set room prices for a given month to maximize revenue without scaring guests.

Reason like a seasoned local revenue manager and weigh:
- Weekends (Fri/Sat) usually command a premium on the Albanian Riviera.
- High season = July–August (peak), shoulder = June & September, low = the rest.
- SOURCE-MARKET demand that floods Ksamil: Italian Ferragosto (~Aug 15) and the whole of
  August (Italian + Albanian-diaspora holidays), Greek/Italian long weekends, public holidays.
- Specific local events the owner tells you about (festivals, concerts) — weight these heavily.
- Booking pace: compare this year's bookings for the period to last year's; behind = soften,
  ahead = push.
- Last-minute: near-term dates that are still empty should be discounted modestly, not left to rot.

Rules:
- Group dates into a FEW meaningful recommendations (date ranges), never per-day noise.
- It is correct to recommend HOLD (keep current price) on ordinary dates — do not always raise.
- Suggested prices must be realistic relative to the current price (rarely more than +60% or below -25%).
- Write every label and reason in ALBANIAN (shqip), short, concrete, owner-friendly — explain WHY.
- Always call the submit_pricing_plan tool. Never reply in plain text.
TXT;
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<int,string>  $events
     */
    private static function userPrompt(array $context, array $events, Carbon $from, Carbon $to): string
    {
        $eventLines = $events ? implode("\n", array_map(fn ($e) => '- '.$e, $events)) : '(asnjë i shënuar)';

        return "Hartoji çmimet për këtë hotel për periudhën {$context['period']}.\n\n"
            ."KONTEKSTI I HOTELIT (JSON):\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\n"
            ."EVENTE QË DI PRONARI:\n{$eventLines}\n\n"
            ."Kthe një plan me disa rekomandime (intervale datash) duke përdorur tool-in submit_pricing_plan. "
            ."Për çdo rekomandim jep çmimin e sugjeruar për ÇDO tip dhome (room_type_id + suggested), "
            ."veprimin (raise/hold/lower), përqindjen e përafërt, arsyen në shqip, dhe nëse mundesh një vlerësim "
            ."të përafërt të të ardhurave shtesë (projected_extra).";
    }

    /** @return array<string,mixed> */
    private static function tool(): array
    {
        return [
            'name' => 'submit_pricing_plan',
            'description' => 'Submit the room-pricing recommendations for the requested period.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => 'One-line summary of the plan, in Albanian.'],
                    'recommendations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD (first night).'],
                                'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD (last night, inclusive).'],
                                'label' => ['type' => 'string', 'description' => 'Short Albanian label, e.g. "Festa e Sarandës".'],
                                'reason' => ['type' => 'string', 'description' => 'Plain Albanian reasoning — the WHY.'],
                                'action' => ['type' => 'string', 'enum' => ['raise', 'hold', 'lower']],
                                'adjustment_pct' => ['type' => 'number', 'description' => 'Approx % vs current (0 for hold).'],
                                'prices' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'room_type_id' => ['type' => 'integer'],
                                            'room_type_name' => ['type' => 'string'],
                                            'current' => ['type' => 'number'],
                                            'suggested' => ['type' => 'number'],
                                        ],
                                        'required' => ['room_type_id', 'suggested'],
                                    ],
                                ],
                                'projected_extra' => ['type' => 'string', 'description' => 'Optional Albanian note, e.g. "+€2,400".'],
                            ],
                            'required' => ['date_from', 'date_to', 'label', 'reason', 'action', 'prices'],
                        ],
                    ],
                ],
                'required' => ['recommendations'],
            ],
        ];
    }
}
