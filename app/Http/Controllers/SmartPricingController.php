<?php

namespace App\Http\Controllers;

use App\Jobs\PushRoomTypeAri;
use App\Models\AuditLog;
use App\Models\RateOverride;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\AiPricing;
use App\Services\SmartPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SmartPricingController extends Controller
{
    /** Fallback sanity band when the owner has not set min/max on the type. */
    private const MIN_BAND = 0.25;
    private const MAX_BAND = 4.0;

    /**
     * A price outside the owner's min/max (or, when unset, 0.25×–4× of base)
     * is treated as a fat-finger / hallucination and never reaches the OTAs.
     */
    private function priceOutOfBand(float $price, ?RoomType $type): bool
    {
        if (! $type) {
            return false;
        }
        $base = (float) $type->base_price;
        // priceBounds() normalizes an inverted min>max pair to unset, so the
        // guard can never reject a price the engine itself suggested.
        [$min, $max] = $type->priceBounds();
        $min ??= $base > 0 ? $base * self::MIN_BAND : null;
        $max ??= $base > 0 ? $base * self::MAX_BAND : null;

        return ($min !== null && $price < $min) || ($max !== null && $price > $max);
    }

    public function index(Request $request): Response
    {
        // min/max MUST ride along: the engine clamps off these attributes, and
        // a partial model would silently read them as null (no clamp at all).
        $types = RoomType::orderBy('name')->get(['id', 'name', 'base_price', 'min_price', 'max_price']);

        $base = [
            'roomTypes' => $types->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name,
                'min_price' => $t->min_price !== null ? (float) $t->min_price : null,
                'max_price' => $t->max_price !== null ? (float) $t->max_price : null,
            ])->values(),
            'strategy' => \App\Services\PricingEngine::strategy(),
            'currency' => Setting::get('financial.default_currency_symbol', '€'),
            'aiConfigured' => AiPricing::configured(),
            // Page-level OTA sync pulse (pushes are per-type full-window, so a
            // per-date pushed/pending status does not exist in the data model).
            'lastSyncAt' => \App\Models\ChannelSyncLog::where('direction', 'push')
                ->where('status', 'ok')->latest('id')->value('created_at')?->toDateTimeString(),
            'upcomingEvents' => \App\Models\PricingEvent::betweenDates(Carbon::today(), Carbon::today()->addDays(90))
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'date_from' => $e->resolved_from->toDateString(),
                    'date_to' => $e->resolved_to->toDateString(),
                    'uplift_pct' => $e->uplift_pct !== null ? (float) $e->uplift_pct : null,
                    'source' => $e->source,
                    'recurring' => (bool) $e->recurring,
                ])->sortBy('date_from')->values(),
            'latestReport' => \App\Models\PricingReport::latest('week_start')->first(),
            'autopilot' => [
                'enabled' => filter_var(Setting::get('pricing.autopilot.enabled', '0'), FILTER_VALIDATE_BOOL),
                'materiality_pct' => (float) Setting::get('pricing.autopilot.materiality_pct', 5),
                'daily_cap_pct' => (float) Setting::get('pricing.autopilot.daily_cap_pct', 15),
                'protect_manual_days' => (int) Setting::get('pricing.autopilot.protect_manual_days', 3),
                'pause_from' => Setting::get('pricing.autopilot.pause_from'),
                'pause_to' => Setting::get('pricing.autopilot.pause_to'),
                'logs' => \App\Models\PricingAutopilotLog::with('roomType:id,name')
                    ->latest('id')->limit(20)->get()
                    ->map(fn ($l) => [
                        'id' => $l->id,
                        'date' => $l->date->toDateString(),
                        'room_type' => $l->roomType?->name,
                        'old_price' => $l->old_price !== null ? (float) $l->old_price : null,
                        'new_price' => (float) $l->new_price,
                        'reverted' => (bool) $l->reverted_at,
                        'at' => $l->created_at->toDateTimeString(),
                    ]),
            ],
        ];

        if ($types->isEmpty()) {
            $today = Carbon::today()->startOfMonth();

            return Inertia::render('Pricing/Smart', array_merge($base, [
                'selectedTypeId' => null, 'days' => [],
                'month' => $today->toDateString(),
                'prevMonth' => $today->copy()->subMonth()->toDateString(),
                'nextMonth' => $today->copy()->addMonth()->toDateString(),
            ]));
        }

        $selected = $types->firstWhere('id', (int) $request->input('room_type_id')) ?? $types->first();

        $month = $request->filled('month')
            ? Carbon::parse($request->input('month'))->startOfMonth()
            : Carbon::today()->startOfMonth();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        return Inertia::render('Pricing/Smart', array_merge($base, [
            'selectedTypeId' => $selected->id,
            'month' => $from->toDateString(),
            'prevMonth' => $from->copy()->subMonth()->toDateString(),
            'nextMonth' => $from->copy()->addMonth()->toDateString(),
            'days' => SmartPricing::calendar($selected, $from, $to),
        ]));
    }

    /** Accept a suggestion → set the price for that single date + room type. */
    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'room_type_id' => ['required', 'exists:room_types,id'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
        ]);

        // Guard against an order-of-magnitude typo reaching the live OTA (e.g. €1 or €900k).
        $type = RoomType::find($data['room_type_id']);
        if ($this->priceOutOfBand((float) $data['price'], $type)) {
            return back()->with('error', "Çmimi {$data['price']} është jashtë kufijve të lejuar për këtë tip dhome. Kontrollo shumën (ose kufijtë min/max).");
        }

        // whereDate matches on the date part (the column may carry a 00:00:00 time), so a
        // re-apply UPDATES the existing row instead of hitting the unique(date,type) index.
        $override = RateOverride::whereDate('date', $data['date'])
            ->where('room_type_id', $data['room_type_id'])
            ->first()
            ?? new RateOverride(['date' => $data['date'], 'room_type_id' => $data['room_type_id']]);

        $override->price = $data['price'];
        $override->created_by = auth()->id();
        $override->save();

        AuditLog::record('pricing.smart_apply', $override, $data);

        // Price changed for this date -> re-push that room type to Channex.
        PushRoomTypeAri::dispatch((int) $data['room_type_id']);

        return back()->with('success', 'Çmimi u aplikua për këtë datë.');
    }

    /** Remove a date override → revert that date to the seasonal/base price. */
    public function remove(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'room_type_id' => ['required', 'exists:room_types,id'],
        ]);

        RateOverride::whereDate('date', $data['date'])
            ->where('room_type_id', $data['room_type_id'])
            ->delete();

        // Price reverted for this date -> re-push that room type to Channex.
        PushRoomTypeAri::dispatch((int) $data['room_type_id']);

        return back()->with('success', 'Çmimi u rikthye te tarifa normale.');
    }

    /** One slider, three presets — the only tuning knob the owner needs. */
    public function updateStrategy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'strategy' => ['required', 'in:kujdesshem,balancuar,agresiv'],
        ]);

        Setting::set('pricing.strategy', $data['strategy']);
        AuditLog::record('pricing.strategy', null, $data);

        return back()->with('success', 'Strategjia e çmimeve u ndryshua.');
    }

    /** Per-type price guardrails, editable right on the pricing screen. */
    public function updateBounds(Request $request, RoomType $roomType): RedirectResponse
    {
        $data = $request->validate([
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', function ($attr, $value, $fail) use ($request) {
                $min = $request->input('min_price');
                if ($value !== null && $value !== '' && $min !== null && $min !== '' && (float) $value < (float) $min) {
                    $fail('Çmimi maksimal duhet të jetë ≥ çmimit minimal.');
                }
            }],
        ]);

        $roomType->update([
            'min_price' => $data['min_price'] !== null && $data['min_price'] !== '' ? $data['min_price'] : null,
            'max_price' => $data['max_price'] !== null && $data['max_price'] !== '' ? $data['max_price'] : null,
        ]);
        AuditLog::record('pricing.bounds', $roomType, $data);

        return back()->with('success', 'Kufijtë e çmimit u ruajtën.');
    }

    /**
     * Bulk-accept the ENGINE\'s suggestions for a range (Apliko javën/muajin).
     * The server recomputes every price — client-sent prices are never trusted.
     */
    public function applyRange(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'exists:room_types,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'])->startOfDay();
        if ($from->diffInDays($to) > 35) {
            return back()->with('error', 'Intervali është shumë i gjatë (maksimumi një muaj).');
        }

        $type = RoomType::findOrFail($data['room_type_id']);
        $suggestions = collect(\App\Services\PricingEngine::forRange($type, $from, $to))
            ->filter(fn ($d) => $d['actionable'] && ! $d['is_past']);

        if ($suggestions->isEmpty()) {
            return back()->with('error', 'S\'ka sugjerime për t\'u aplikuar në këtë interval.');
        }

        DB::transaction(function () use ($suggestions, $type) {
            foreach ($suggestions as $day) {
                $override = RateOverride::whereDate('date', $day['date'])
                    ->where('room_type_id', $type->id)->first()
                    ?? new RateOverride(['date' => $day['date'], 'room_type_id' => $type->id]);
                $override->price = $day['suggested_price'];
                $override->created_by = auth()->id();
                $override->save();
            }
            AuditLog::record('pricing.range_apply', $type, [
                'dates' => $suggestions->keys()->values()->all(),
                'count' => $suggestions->count(),
            ]);
        });

        PushRoomTypeAri::dispatch($type->id);

        return back()->with('success', 'U aplikuan '.$suggestions->count().' çmime — po dërgohen te OTA-t.');
    }

    /** "✦ Shpjegim AI" — one cached Albanian sentence for a day's breakdown. */
    public function explain(Request $request)
    {
        if (! AiPricing::configured()) {
            return response()->json(['error' => 'Asistenti AI nuk është konfiguruar.'], 422);
        }
        $data = $request->validate([
            'date' => ['required', 'date'],
            'room_type_id' => ['required', 'exists:room_types,id'],
        ]);

        try {
            $sentence = AiPricing::explain(RoomType::findOrFail($data['room_type_id']), $data['date']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => self::safeAiError($e)], 502);
        }

        return response()->json(['sentence' => $sentence]);
    }

    /** Gemini proposes demand events; NOTHING is written until the owner approves. */
    public function suggestEvents()
    {
        if (! AiPricing::configured()) {
            return response()->json(['error' => 'Asistenti AI nuk është konfiguruar.'], 422);
        }

        try {
            return response()->json(['events' => AiPricing::suggestEvents()]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => self::safeAiError($e)], 502);
        }
    }

    /** Owner approval turns a suggestion into a real pricing_events row. */
    public function approveEvent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'uplift_pct' => ['nullable', 'numeric', 'between:-50,100'],
        ]);

        \App\Models\PricingEvent::create($data + ['source' => 'ai', 'created_by' => auth()->id()]);
        AuditLog::record('pricing.event_approve', null, $data);

        return back()->with('success', 'Eventi u shtua — motori do ta marrë parasysh.');
    }

    /** Remove an event from the calendar (any source). */
    public function destroyEvent(\App\Models\PricingEvent $pricingEvent): RedirectResponse
    {
        AuditLog::record('pricing.event_delete', $pricingEvent, ['name' => $pricingEvent->name]);
        $pricingEvent->delete();

        return back()->with('success', 'Eventi u hoq.');
    }

    /** Calendar Q&A — grounded strictly in the engine's data for the month. */
    public function ask(Request $request)
    {
        if (! AiPricing::configured()) {
            return response()->json(['error' => 'Asistenti AI nuk është konfiguruar.'], 422);
        }
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'month' => ['required', 'date'],
            'room_type_id' => ['nullable', 'exists:room_types,id'],
        ]);

        try {
            $answer = AiPricing::ask(
                $data['question'],
                $data['month'],
                isset($data['room_type_id']) ? RoomType::find($data['room_type_id']) : null,
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => self::safeAiError($e)], 502);
        }

        return response()->json(['answer' => $answer]);
    }

    /** "Gjenero tani" — refresh this week's report on demand. */
    public function generateReport(): RedirectResponse
    {
        if (! AiPricing::configured()) {
            return back()->with('error', 'Asistenti AI nuk është konfiguruar. Shto çelësin te Settings → Asistenti AI.');
        }

        \Illuminate\Support\Facades\Artisan::call('pricing:weekly-report');

        return back()->with('success', 'Raporti javor u gjenerua.');
    }

    /** Owner-safe Albanian error, guaranteed key-free (key travels in a header). */
    private static function safeAiError(\Throwable $e): string
    {
        $msg = preg_replace('/key=[A-Za-z0-9._\-]+/', 'key=***', $e->getMessage());

        return trim(mb_strimwidth((string) $msg, 0, 200, '…')) ?: "Asistenti AI s'u përgjigj. Provoni përsëri.";
    }

    /** Autopilot switch + guardrail knobs (pilot-then-confirm: explicit action). */
    public function updateAutopilot(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'materiality_pct' => ['required', 'numeric', 'between:1,50'],
            'daily_cap_pct' => ['required', 'numeric', 'between:1,50'],
            'protect_manual_days' => ['required', 'integer', 'between:0,30'],
            'pause_from' => ['nullable', 'date', 'required_with:pause_to'],
            'pause_to' => ['nullable', 'date', 'required_with:pause_from', 'after_or_equal:pause_from'],
        ]);

        Setting::set('pricing.autopilot.enabled', $data['enabled'] ? '1' : '0');
        Setting::set('pricing.autopilot.materiality_pct', (string) $data['materiality_pct']);
        Setting::set('pricing.autopilot.daily_cap_pct', (string) $data['daily_cap_pct']);
        Setting::set('pricing.autopilot.protect_manual_days', (string) $data['protect_manual_days']);
        Setting::set('pricing.autopilot.pause_from', $data['pause_from'] ?? null);
        Setting::set('pricing.autopilot.pause_to', $data['pause_to'] ?? null);
        AuditLog::record('pricing.autopilot_settings', null, $data);

        return back()->with('success', $data['enabled']
            ? 'Autopiloti u NDEZ — do aplikojë vetëm brenda kufijve të tu.'
            : 'Autopiloti u fik.');
    }

    /** 1-tap "Kthe": restore the pre-autopilot price and re-push to the OTAs. */
    public function revertAutopilot(\App\Models\PricingAutopilotLog $log): RedirectResponse
    {
        if ($log->reverted_at) {
            return back()->with('error', 'Ky ndryshim është kthyer tashmë.');
        }

        DB::transaction(function () use ($log) {
            $override = RateOverride::whereDate('date', $log->date->toDateString())
                ->where('room_type_id', $log->room_type_id)->first();

            if ($log->old_price === null) {
                $override?->delete(); // there was no override before — back to the seasonal price
            } else {
                $override ??= new RateOverride(['date' => $log->date->toDateString(), 'room_type_id' => $log->room_type_id]);
                $override->price = $log->old_price;
                $override->created_by = auth()->id(); // reverting IS the owner's hand — protected from re-apply
                $override->save();
            }

            $log->update(['reverted_at' => now()]);
            AuditLog::record('pricing.autopilot_revert', $log, ['date' => $log->date->toDateString()]);
        });

        PushRoomTypeAri::dispatch($log->room_type_id);

        return back()->with('success', 'Çmimi u kthye — po dërgohet te OTA-t.');
    }
}
