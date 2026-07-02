<?php

namespace App\Services;

use App\Models\PricingEvent;
use App\Models\RateOverride;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\WebsiteSearchLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Gemini's FOUR jobs in Çmim Inteligjent 2.0 — and only these (ratified
 * design: the deterministic PricingEngine computes every number; the LLM
 * explains, contextualizes and advises, NEVER sets a price):
 *  1. explain()       — one Albanian sentence for a day's factor breakdown
 *  2. suggestEvents() — candidate demand events the OWNER approves
 *  3. weeklyReport()  — the Monday pricing narrative
 *  4. ask()           — calendar Q&A grounded in real engine data
 */
class AiPricing
{
    private const HOTEL_CONTEXT = 'Small beach hotel "Villa Mucho" in Ksamil, Sarandë, Albania (8 rooms; guests mostly Albanian, Italian, Greek, Kosovar; peak July–August, Ferragosto floods the town).';

    public static function configured(): bool
    {
        return app(GeminiClient::class)->configured();
    }

    /**
     * One plain-Albanian sentence explaining a day's deterministic breakdown.
     * Cached on the exact inputs, so repeat clicks cost nothing.
     */
    public static function explain(RoomType $type, string $date): string
    {
        $day = PricingEngine::forRange($type, Carbon::parse($date), Carbon::parse($date))[$date] ?? null;
        if (! $day) {
            return 'S\'ka të dhëna për këtë datë.';
        }
        if (empty($day['factors'])) {
            return 'Asnjë faktor kërkese aktiv — vlen çmimi bazë/sezonal.';
        }

        $payload = [
            'date' => $date,
            'room_type' => $type->name,
            'reference' => $day['reference'],
            'suggested' => $day['suggested_price'],
            'factors' => $day['factors'],
            'clamped' => $day['clamped'],
            'occupancy_pct' => $day['occupancy_pct'],
        ];

        $cacheKey = 'ai.explain.'.md5(json_encode($payload));

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($payload) {
            $out = app(GeminiClient::class)->structured(
                'You explain hotel price suggestions to a non-technical Albanian owner. '
                .self::HOTEL_CONTEXT.' You are given the DETERMINISTIC factor breakdown that '
                .'produced a suggested price. Write ONE short, warm, concrete sentence in '
                .'ALBANIAN that explains WHY, citing the strongest factors in human terms. '
                .'Never invent numbers not present in the data. Always call submit_explanation.',
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                [
                    'name' => 'submit_explanation',
                    'description' => 'Submit the one-sentence Albanian explanation.',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => ['sentence' => ['type' => 'string']],
                        'required' => ['sentence'],
                    ],
                ],
                'submit_explanation',
                1024,
                25,
            );

            return trim((string) ($out['sentence'] ?? '')) ?: 'S\'u gjenerua dot shpjegimi.';
        });
    }

    /**
     * Candidate demand events for the next ~6 months. SUGGEST-ONLY: the owner
     * approves each into pricing_events; nothing is written here.
     *
     * @return array<int,array{name:string,date_from:string,date_to:string,uplift_pct:?float,reason:string}>
     */
    public static function suggestEvents(): array
    {
        $from = Carbon::today();
        $to = $from->copy()->addMonths(6);
        $existing = PricingEvent::betweenDates($from, $to)
            ->map(fn ($e) => $e->name.' ('.$e->resolved_from->toDateString().' → '.$e->resolved_to->toDateString().')')
            ->values()->all();

        $out = app(GeminiClient::class)->structured(
            'You maintain the demand-events calendar for pricing. '.self::HOTEL_CONTEXT.' '
            .'Suggest REAL demand-relevant events in the given window that are MISSING from the '
            .'existing list: Albanian & Kosovar public/religious holidays (incl. Bajram dates for '
            .'the actual year), Italian holidays that push Ksamil demand, Saranda/Ksamil festivals, '
            .'diaspora waves. For each: exact dates, a conservative uplift_pct suggestion (5-20, or '
            .'null if purely informational), and a one-line Albanian reason. Skip anything already '
            .'covered. Always call submit_event_suggestions.',
            json_encode([
                'window' => $from->toDateString().' → '.$to->toDateString(),
                'existing_events' => $existing,
            ], JSON_UNESCAPED_UNICODE),
            [
                'name' => 'submit_event_suggestions',
                'description' => 'Submit candidate demand events for owner approval.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'events' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                                    'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                                    'uplift_pct' => ['type' => 'number', 'description' => 'Suggested % uplift, or omit'],
                                    'reason' => ['type' => 'string', 'description' => 'One Albanian line'],
                                ],
                                'required' => ['name', 'date_from', 'date_to', 'reason'],
                            ],
                        ],
                    ],
                    'required' => ['events'],
                ],
            ],
            'submit_event_suggestions',
            4096,
            40,
        );

        return array_values(array_filter($out['events'] ?? [], function ($e) {
            return ! empty($e['name']) && ! empty($e['date_from']) && ! empty($e['date_to'])
                && strtotime($e['date_from']) !== false && strtotime($e['date_to']) !== false;
        }));
    }

    /**
     * The Monday narrative: deterministic stats in, Albanian advice out.
     *
     * @return array{title:string, body:string, highlights:array<int,string>}
     */
    public static function weeklyReport(): array
    {
        $out = app(GeminiClient::class)->structured(
            'You write the weekly pricing report for a non-technical Albanian hotel owner. '
            .self::HOTEL_CONTEXT.' You are given DETERMINISTIC stats (occupancy, engine '
            .'suggestions, lost searches, applied prices). Write in ALBANIAN: a short title, '
            .'a friendly 4-8 sentence body (what happened, what stands out, what to do this '
            .'week), and 2-4 one-line highlights. Cite only numbers present in the data. '
            .'Always call submit_weekly_report.',
            json_encode(self::weeklyStats(), JSON_UNESCAPED_UNICODE),
            [
                'name' => 'submit_weekly_report',
                'description' => 'Submit the weekly Albanian pricing report.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                        'highlights' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['title', 'body'],
                ],
            ],
            'submit_weekly_report',
            4096,
            40,
        );

        return [
            'title' => trim((string) ($out['title'] ?? 'Raporti javor i çmimeve')),
            'body' => trim((string) ($out['body'] ?? '')),
            'highlights' => array_values(array_filter(array_map('strval', $out['highlights'] ?? []))),
        ];
    }

    /**
     * Calendar Q&A: answer the owner's question grounded ONLY in real engine
     * data for the visible month (plus events + strategy).
     */
    public static function ask(string $question, string $month, ?RoomType $type = null): string
    {
        $from = Carbon::parse($month)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $type ??= RoomType::orderBy('name')->first();
        if (! $type) {
            return 'S\'ka ende tipe dhomash.';
        }

        $days = collect(PricingEngine::forRange($type, $from, $to))
            ->map(fn ($d) => [
                'date' => $d['date'],
                'occ' => $d['occupancy_pct'],
                'current' => $d['current_price'],
                'suggested' => $d['actionable'] ? $d['suggested_price'] : null,
                'factors' => array_map(fn ($f) => $f['label'].' '.($f['pct'] > 0 ? '+' : '').$f['pct'].'%', $d['factors']),
                'clamped' => $d['clamped'],
            ])->values()->all();

        $grounding = [
            'room_type' => $type->name,
            'month' => $from->toDateString().' → '.$to->toDateString(),
            'strategy' => PricingEngine::strategy(),
            'days' => $days,
            'events' => PricingEvent::betweenDates($from, $to)
                ->map(fn ($e) => $e->name.' ('.$e->resolved_from->toDateString().' → '.$e->resolved_to->toDateString().')')->values()->all(),
        ];

        $out = app(GeminiClient::class)->structured(
            'You answer a hotel owner\'s pricing questions in ALBANIAN, grounded STRICTLY in '
            .'the provided deterministic engine data. '.self::HOTEL_CONTEXT.' If the data does '
            .'not contain the answer, say so honestly. Keep it to 2-4 sentences, concrete, '
            .'citing the dates/factors from the data. You may give advice, but NEVER present a '
            .'price of your own invention as the system\'s. Always call submit_answer.',
            "PYETJA E PRONARIT: {$question}\n\nTË DHËNAT (JSON):\n".json_encode($grounding, JSON_UNESCAPED_UNICODE),
            [
                'name' => 'submit_answer',
                'description' => 'Submit the grounded Albanian answer.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['answer' => ['type' => 'string']],
                    'required' => ['answer'],
                ],
            ],
            'submit_answer',
            2048,
            25,
        );

        return trim((string) ($out['answer'] ?? '')) ?: 'S\'u gjenerua dot përgjigja. Provo përsëri.';
    }

    /**
     * Deterministic inputs for the weekly report — every number the narrative
     * may cite comes from HERE, not from the model's imagination.
     *
     * @return array<string,mixed>
     */
    public static function weeklyStats(): array
    {
        $today = Carbon::today();
        $types = RoomType::orderBy('name')->get();
        $rooms = Room::where('status', '!=', 'maintenance')->count();

        $occupancy = [];
        $suggestions = 0;
        $hotDates = [];
        foreach ($types as $type) {
            foreach (PricingEngine::forRange($type, $today, $today->copy()->addDays(29)) as $d) {
                $occupancy[$d['date']] = ($occupancy[$d['date']] ?? 0) + $d['booked'];
                if ($d['actionable']) {
                    $suggestions++;
                    if ($d['adjustment_pct'] >= 15) {
                        $hotDates[] = $d['date'].' ('.$type->name.' '.($d['adjustment_pct'] > 0 ? '+' : '').$d['adjustment_pct'].'%)';
                    }
                }
            }
        }
        $occPct = collect($occupancy)->map(fn ($b) => $rooms > 0 ? round($b / $rooms * 100) : 0);

        return [
            'week_of' => $today->copy()->startOfWeek()->toDateString(),
            'strategy' => PricingEngine::strategy(),
            'rooms_sellable' => $rooms,
            'next_30d' => [
                'avg_occupancy_pct' => round($occPct->avg() ?? 0),
                'max_occupancy_pct' => (int) ($occPct->max() ?? 0),
                'open_engine_suggestions' => $suggestions,
                'hot_dates' => array_slice($hotDates, 0, 8),
            ],
            'last_7d' => [
                'website_searches' => WebsiteSearchLog::where('created_at', '>=', $today->copy()->subDays(7))->count(),
                'denied_searches' => WebsiteSearchLog::where('created_at', '>=', $today->copy()->subDays(7))->where('denied', true)->count(),
                'prices_applied' => RateOverride::where('updated_at', '>=', $today->copy()->subDays(7))->count(),
            ],
            'upcoming_events' => PricingEvent::betweenDates($today, $today->copy()->addDays(30))
                ->map(fn ($e) => $e->name.' ('.$e->resolved_from->toDateString().')')->values()->all(),
        ];
    }
}
