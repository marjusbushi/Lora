<?php

namespace App\Http\Controllers;

use App\Jobs\PushRoomTypeAri;
use App\Models\AuditLog;
use App\Models\ChannelSyncLog;
use App\Models\PricingAutopilotLog;
use App\Models\PricingEvent;
use App\Models\PricingManualProtection;
use App\Models\PricingReport;
use App\Models\RateOverride;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\AiPricing;
use App\Services\PricingEngine;
use App\Services\PricingRulesVersion;
use App\Services\RoomPricing;
use App\Services\SmartPricing;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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
            'strategy' => PricingEngine::strategy(),
            'currency' => Setting::get('financial.default_currency_symbol', '€'),
            'aiConfigured' => AiPricing::configured(),
            // Page-level OTA sync pulse (pushes are per-type full-window, so a
            // per-date pushed/pending status does not exist in the data model).
            'lastSyncAt' => ChannelSyncLog::where('direction', 'push')
                ->where('status', 'ok')->latest('id')->value('created_at')?->toDateTimeString(),
            'upcomingEvents' => PricingEvent::betweenDates(Carbon::today(), Carbon::today()->addDays(90))
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'date_from' => $e->resolved_from->toDateString(),
                    'date_to' => $e->resolved_to->toDateString(),
                    'uplift_pct' => $e->uplift_pct !== null ? (float) $e->uplift_pct : null,
                    'affects_price' => $e->uplift_pct !== null && (float) $e->uplift_pct != 0.0,
                    'source' => $e->source,
                    'recurring' => (bool) $e->recurring,
                ])->sortBy('date_from')->values(),
            'latestReport' => PricingReport::latest('week_start')->first(),
            'autopilot' => [
                'enabled' => filter_var(Setting::get('pricing.autopilot.enabled', '0'), FILTER_VALIDATE_BOOL),
                'materiality_pct' => (float) Setting::get('pricing.autopilot.materiality_pct', 5),
                'daily_cap_pct' => (float) Setting::get('pricing.autopilot.daily_cap_pct', 15),
                'protect_manual_days' => (int) Setting::get('pricing.autopilot.protect_manual_days', 3),
                'pause_from' => Setting::get('pricing.autopilot.pause_from'),
                'pause_to' => Setting::get('pricing.autopilot.pause_to'),
                'logs' => PricingAutopilotLog::with('roomType:id,name')
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

    /** Apply a current engine suggestion or an explicit owner-entered price. */
    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'room_type_id' => ['required', TenantRule::exists('room_types')],
            // Missing price means "accept the engine suggestion"; the server
            // recomputes it now so a stale browser object is never trusted.
            'price' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
        ]);

        $source = array_key_exists('price', $data) && $data['price'] !== null ? 'manual' : 'engine';
        $manualPrice = $source === 'manual' ? (float) $data['price'] : null;
        $result = DB::transaction(function () use ($data, $manualPrice, $source) {
            // Engine accepts lock the rules version before reading inputs, so
            // an event/strategy/base-rate write cannot make this suggestion stale.
            if ($source === 'engine') {
                PricingRulesVersion::lock();
            }
            $lockedType = RoomType::query()->whereKey($data['room_type_id'])->lockForUpdate()->firstOrFail();
            if ($source === 'engine') {
                $date = Carbon::parse($data['date'])->startOfDay();
                $day = PricingEngine::forRange($lockedType, $date, $date)[$date->toDateString()] ?? null;
                if (! $day || ! $day['actionable'] || $day['is_past']) {
                    return ['state' => 'stale'];
                }
                $price = (float) $day['suggested_price'];
            } else {
                $price = $manualPrice;
            }

            if ($price === null || $price <= 0 || $this->priceOutOfBand($price, $lockedType)) {
                return ['state' => 'unsafe', 'price' => $price];
            }

            $override = RateOverride::query()
                ->whereDate('date', $data['date'])
                ->where('room_type_id', $lockedType->id)
                ->lockForUpdate()
                ->first()
                ?? new RateOverride(['date' => $data['date'], 'room_type_id' => $lockedType->id]);

            $override->price = $price;
            $override->created_by = auth()->id();
            $override->save();

            AuditLog::record('pricing.smart_apply', $override, [
                'date' => $data['date'],
                'room_type_id' => $lockedType->id,
                'price' => $price,
                'source' => $source,
            ]);

            return ['state' => 'saved', 'price' => $price];
        }, 3);

        if ($result['state'] === 'stale') {
            return back()->with('error', 'Sugjerimi ka ndryshuar ose nuk është më aktiv. Kalendari u rifreskua; kontrolloje përsëri.');
        }
        if ($result['state'] === 'unsafe') {
            return back()->with('error', 'Çmimi është jashtë kufijve të lejuar për këtë tip dhome. Asgjë nuk u aplikua.');
        }

        // Price changed for this date -> re-push that room type to Channex.
        PushRoomTypeAri::dispatch((int) $data['room_type_id']);

        return back()->with('success', 'Çmimi '.number_format($result['price'], 2, '.', '').' u aplikua për këtë datë.');
    }

    /** Remove a date override → revert that date to the seasonal/base price. */
    public function remove(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'room_type_id' => ['required', TenantRule::exists('room_types')],
        ]);

        $removed = DB::transaction(function () use ($data) {
            PricingRulesVersion::lock();
            $type = RoomType::query()->whereKey($data['room_type_id'])->lockForUpdate()->firstOrFail();
            $normalPrice = RoomPricing::seasonPrice($type, $data['date']);
            if ($normalPrice <= 0 || $this->priceOutOfBand($normalPrice, $type)) {
                return false;
            }

            $protection = PricingManualProtection::query()
                ->whereDate('date', $data['date'])
                ->where('room_type_id', $type->id)
                ->lockForUpdate()
                ->first();
            $override = RateOverride::query()
                ->whereDate('date', $data['date'])
                ->where('room_type_id', $type->id)
                ->lockForUpdate()
                ->first();
            $override?->delete();

            $protection ??= new PricingManualProtection([
                'date' => $data['date'],
                'room_type_id' => $type->id,
            ]);
            $protection->created_by = auth()->id();
            $protection->reason = 'remove_override';
            $protection->save();

            AuditLog::record('pricing.smart_remove', $type, [
                'date' => $data['date'],
                'normal_price' => $normalPrice,
            ]);

            return true;
        }, 3);

        if (! $removed) {
            return back()->with('error', 'Tarifa normale është jashtë kufijve aktualë. Për siguri, çmimi nuk u hoq.');
        }

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

        $changed = DB::transaction(function () use ($data) {
            $version = PricingRulesVersion::lock();
            if (PricingEngine::strategy() === $data['strategy']) {
                return false;
            }

            Setting::set('pricing.strategy', $data['strategy']);
            PricingRulesVersion::increment($version);
            AuditLog::record('pricing.strategy', null, $data);

            return true;
        }, 3);

        if (! $changed) {
            return back()->with('success', 'Strategjia ishte tashmë e zgjedhur.');
        }

        return back()->with('success', 'Strategjia e çmimeve u ndryshua.');
    }

    /** Per-type price guardrails, editable right on the pricing screen. */
    public function updateBounds(Request $request, RoomType $roomType): RedirectResponse
    {
        $data = $request->validate([
            'min_price' => ['nullable', 'numeric', 'min:0.01'],
            'max_price' => ['nullable', 'numeric', 'min:0.01', function ($attr, $value, $fail) use ($request) {
                $min = $request->input('min_price');
                if ($value !== null && $value !== '' && $min !== null && $min !== '' && (float) $value < (float) $min) {
                    $fail('Çmimi maksimal duhet të jetë ≥ çmimit minimal.');
                }
            }],
        ]);

        DB::transaction(function () use ($data, $roomType) {
            $version = PricingRulesVersion::lock();
            $lockedType = RoomType::query()->whereKey($roomType->id)->lockForUpdate()->firstOrFail();
            $lockedType->update([
                'min_price' => $data['min_price'] !== null && $data['min_price'] !== '' ? $data['min_price'] : null,
                'max_price' => $data['max_price'] !== null && $data['max_price'] !== '' ? $data['max_price'] : null,
            ]);
            if ($lockedType->wasChanged(['min_price', 'max_price'])) {
                PricingRulesVersion::increment($version);
            }
            AuditLog::record('pricing.bounds', $lockedType, $data);
        }, 3);

        return back()->with('success', 'Kufijtë e çmimit u ruajtën.');
    }

    /**
     * Bulk-accept the ENGINE\'s suggestions for a range (Apliko javën/muajin).
     * The server recomputes every price — client-sent prices are never trusted.
     */
    public function applyRange(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', TenantRule::exists('room_types')],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'])->startOfDay();
        if ($from->diffInDays($to) > 35) {
            return back()->with('error', 'Intervali është shumë i gjatë (maksimumi një muaj).');
        }

        $result = DB::transaction(function () use ($data, $from, $to) {
            PricingRulesVersion::lock();
            $lockedType = RoomType::query()->whereKey($data['room_type_id'])->lockForUpdate()->firstOrFail();
            $suggestions = collect(PricingEngine::forRange($lockedType, $from, $to))
                ->filter(fn ($day) => $day['actionable'] && ! $day['is_past']);
            if ($suggestions->isEmpty()) {
                return ['state' => 'empty'];
            }

            $unsafe = $suggestions->first(fn ($day) => (float) $day['suggested_price'] <= 0
                || $this->priceOutOfBand((float) $day['suggested_price'], $lockedType));
            if ($unsafe) {
                return ['state' => 'unsafe', 'date' => $unsafe['date']];
            }

            foreach ($suggestions as $day) {
                $override = RateOverride::whereDate('date', $day['date'])
                    ->where('room_type_id', $lockedType->id)
                    ->lockForUpdate()
                    ->first()
                    ?? new RateOverride(['date' => $day['date'], 'room_type_id' => $lockedType->id]);
                $override->price = $day['suggested_price'];
                $override->created_by = auth()->id();
                $override->save();
            }
            AuditLog::record('pricing.range_apply', $lockedType, [
                'dates' => $suggestions->keys()->values()->all(),
                'count' => $suggestions->count(),
            ]);

            return ['state' => 'saved', 'count' => $suggestions->count(), 'room_type_id' => $lockedType->id];
        }, 3);

        if ($result['state'] === 'empty') {
            return back()->with('error', 'S\'ka sugjerime për t\'u aplikuar në këtë interval.');
        }
        if ($result['state'] === 'unsafe') {
            return back()->with('error', "Sugjerimi për {$result['date']} është jashtë kufijve të lejuar. Asnjë çmim nuk u aplikua.");
        }

        PushRoomTypeAri::dispatch($result['room_type_id']);

        return back()->with('success', 'U aplikuan '.$result['count'].' çmime — po dërgohen te OTA-t.');
    }

    /** "✦ Shpjegim AI" — one cached Albanian sentence for a day's breakdown. */
    public function explain(Request $request)
    {
        if (! AiPricing::configured()) {
            return response()->json(['error' => 'Asistenti AI nuk është konfiguruar.'], 422);
        }
        $data = $request->validate([
            'date' => ['required', 'date'],
            'room_type_id' => ['required', TenantRule::exists('room_types')],
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
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'uplift_pct' => ['nullable', 'numeric', 'between:-50,100'],
        ]);

        $data['uplift_pct'] = isset($data['uplift_pct']) && (float) $data['uplift_pct'] != 0.0
            ? round((float) $data['uplift_pct'], 2)
            : null;

        $result = DB::transaction(function () use ($data) {
            // This DB row serializes rule writes even when the cache store is
            // not shared across processes.
            $version = PricingRulesVersion::lock();
            $existing = PricingEvent::query()
                ->where('name', $data['name'])
                ->whereDate('date_from', $data['date_from'])
                ->whereDate('date_to', $data['date_to'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $existingUplift = $existing->uplift_pct !== null ? (float) $existing->uplift_pct : null;
                $exactRetry = $existing->source === 'ai' && $existingUplift === $data['uplift_pct'];

                return ['state' => $exactRetry ? 'existing' : 'conflict', 'event' => $existing];
            }

            $event = PricingEvent::create($data + ['source' => 'ai', 'created_by' => auth()->id()]);
            PricingRulesVersion::increment($version);
            // Pricing-rule audit is strict: if the audit row cannot be
            // written, the rule creation rolls back too.
            AuditLog::create([
                'causer_id' => auth()->id(),
                'action' => 'pricing.event_approve',
                'subject_type' => PricingEvent::class,
                'subject_id' => $event->id,
                'properties' => $data,
                'created_at' => now(),
            ]);

            return ['state' => 'created', 'event' => $event];
        }, 3);

        if ($result['state'] === 'conflict') {
            return back()->with('error', "Ekziston tashmë \"{$result['event']->name}\" në këto data me ndikim ose burim tjetër. Kontrolloje para se ta ndryshosh.");
        }
        if ($result['state'] === 'existing') {
            return back()->with('success', "Eventi \"{$result['event']->name}\" ishte shtuar tashmë me të njëjtin ndikim; nuk u dyfishua.");
        }

        return back()->with('success', $data['uplift_pct'] === null
            ? 'Eventi u shtua vetëm si informacion — nuk ndryshon sugjerimet.'
            : 'Eventi u shtua dhe përqindja e tij hyn në sugjerimet e motorit.');
    }

    /** Owner controls whether an existing event affects the deterministic engine. */
    public function updateEvent(Request $request, PricingEvent $pricingEvent): RedirectResponse
    {
        $data = $request->validate([
            'uplift_pct' => ['present', 'nullable', 'numeric', 'between:-50,100'],
        ]);

        $uplift = isset($data['uplift_pct']) && (float) $data['uplift_pct'] != 0.0
            ? round((float) $data['uplift_pct'], 2)
            : null;

        $changed = DB::transaction(function () use ($pricingEvent, $uplift) {
            $version = PricingRulesVersion::lock();
            $lockedEvent = PricingEvent::query()->whereKey($pricingEvent->id)->lockForUpdate()->firstOrFail();
            $old = $lockedEvent->uplift_pct !== null ? (float) $lockedEvent->uplift_pct : null;
            if ($old === $uplift) {
                return false;
            }

            $lockedEvent->update(['uplift_pct' => $uplift]);
            PricingRulesVersion::increment($version);
            AuditLog::create([
                'causer_id' => auth()->id(),
                'action' => 'pricing.event_update',
                'subject_type' => PricingEvent::class,
                'subject_id' => $lockedEvent->id,
                'properties' => [
                    'old_uplift_pct' => $old,
                    'uplift_pct' => $uplift,
                ],
                'created_at' => now(),
            ]);

            return true;
        }, 3);

        if (! $changed) {
            return back()->with('success', 'Ndikimi ishte tashmë i njëjtë — nuk u krijua ndryshim i dyfishtë.');
        }

        return back()->with('success', $uplift === null
            ? 'Eventi mbetet vetëm informacion — nuk ndryshon sugjerimet.'
            : 'Ndikimi i eventit u ruajt. Asnjë çmim nuk u dërgua te OTA-t.');
    }

    /** Remove an event from the calendar (any source). */
    public function destroyEvent(PricingEvent $pricingEvent): RedirectResponse
    {
        DB::transaction(function () use ($pricingEvent) {
            $version = PricingRulesVersion::lock();
            $lockedEvent = PricingEvent::query()->whereKey($pricingEvent->id)->lockForUpdate()->firstOrFail();
            AuditLog::create([
                'causer_id' => auth()->id(),
                'action' => 'pricing.event_delete',
                'subject_type' => PricingEvent::class,
                'subject_id' => $lockedEvent->id,
                'properties' => ['name' => $lockedEvent->name],
                'created_at' => now(),
            ]);
            $lockedEvent->delete();
            PricingRulesVersion::increment($version);
        }, 3);

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
            'room_type_id' => ['nullable', TenantRule::exists('room_types')],
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

        Artisan::call('pricing:weekly-report');

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
            'pause_from' => ['nullable', 'date_format:Y-m-d', 'required_with:pause_to'],
            'pause_to' => ['nullable', 'date_format:Y-m-d', 'required_with:pause_from', 'after_or_equal:pause_from'],
        ]);

        if ($data['enabled']) {
            $missingBounds = RoomType::query()
                ->whereHas('rooms', fn ($q) => $q->where('status', '!=', 'maintenance'))
                ->where(function ($q) {
                    $q->whereNull('min_price')
                        ->orWhereNull('max_price')
                        ->orWhere('min_price', '<=', 0)
                        ->orWhere('max_price', '<=', 0)
                        ->orWhereColumn('min_price', '>', 'max_price');
                })
                ->orderBy('name')
                ->pluck('name');

            if ($missingBounds->isNotEmpty()) {
                return back()->withErrors([
                    'enabled' => 'Autopiloti nuk ndizet pa minimum dhe maksimum për çdo tip aktiv: '.$missingBounds->implode(', ').'.',
                ]);
            }
        }

        DB::transaction(function () use ($data) {
            // Same lock order as the command. All knobs become visible as one
            // configuration change, never a half-old/half-new snapshot.
            Setting::query()
                ->where('group', 'pricing')
                ->whereIn('key', [
                    'autopilot.enabled',
                    'autopilot.materiality_pct',
                    'autopilot.daily_cap_pct',
                    'autopilot.protect_manual_days',
                    'autopilot.pause_from',
                    'autopilot.pause_to',
                ])
                ->orderBy('key')
                ->lockForUpdate()
                ->get();

            Setting::set('pricing.autopilot.enabled', $data['enabled'] ? '1' : '0');
            Setting::set('pricing.autopilot.materiality_pct', (string) $data['materiality_pct']);
            Setting::set('pricing.autopilot.daily_cap_pct', (string) $data['daily_cap_pct']);
            Setting::set('pricing.autopilot.protect_manual_days', (string) $data['protect_manual_days']);
            Setting::set('pricing.autopilot.pause_from', $data['pause_from'] ?? null);
            Setting::set('pricing.autopilot.pause_to', $data['pause_to'] ?? null);
            AuditLog::record('pricing.autopilot_settings', null, $data);
        });

        return back()->with('success', $data['enabled']
            ? 'Autopiloti u NDEZ — do aplikojë vetëm brenda kufijve të tu.'
            : 'Autopiloti u fik.');
    }

    /** 1-tap "Kthe": restore the pre-autopilot price and re-push to the OTAs. */
    public function revertAutopilot(PricingAutopilotLog $log): RedirectResponse
    {
        $result = DB::transaction(function () use ($log) {
            PricingRulesVersion::lock();
            // Canonical price lock order: type → logs → override. The rules
            // mutex precedes it whenever the effective seasonal rate matters.
            $type = RoomType::query()->whereKey($log->room_type_id)->lockForUpdate()->firstOrFail();
            $lockedLog = PricingAutopilotLog::query()
                ->whereKey($log->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedLog->reverted_at) {
                return 'already_reverted';
            }

            $latestLog = PricingAutopilotLog::query()
                ->where('room_type_id', $lockedLog->room_type_id)
                ->whereDate('date', $lockedLog->date->toDateString())
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if (! $latestLog || $latestLog->id !== $lockedLog->id) {
                return 'conflict';
            }

            $protection = PricingManualProtection::query()
                ->whereDate('date', $log->date->toDateString())
                ->where('room_type_id', $log->room_type_id)
                ->lockForUpdate()
                ->first();
            $override = RateOverride::whereDate('date', $log->date->toDateString())
                ->where('room_type_id', $log->room_type_id)
                ->lockForUpdate()
                ->first();

            // Revert only the exact system-written value represented by this
            // log. A missing row, a newer manual price, or another later write
            // means the state has moved on and must not be overwritten.
            if (! $override
                || $override->created_by !== null
                || abs((float) $override->price - (float) $lockedLog->new_price) > 0.009) {
                return 'conflict';
            }

            $restorePrice = $lockedLog->old_price !== null
                ? (float) $lockedLog->old_price
                : RoomPricing::seasonPrice($type, $lockedLog->date);
            if ($restorePrice <= 0 || $this->priceOutOfBand($restorePrice, $type)) {
                return 'unsafe_restore';
            }

            if ($lockedLog->old_price === null) {
                // Return to the real seasonal fallback, and keep the owner's
                // intent in a separate marker so no fake override is shown.
                $override->delete();
                $protection ??= new PricingManualProtection([
                    'date' => $lockedLog->date->toDateString(),
                    'room_type_id' => $lockedLog->room_type_id,
                ]);
                $protection->created_by = auth()->id();
                $protection->reason = 'autopilot_revert';
                $protection->save();
            } else {
                $override->price = $restorePrice;
                $override->created_by = auth()->id();
                $override->save();
                $protection?->delete();
            }

            $lockedLog->update(['reverted_at' => now()]);
            AuditLog::record('pricing.autopilot_revert', $lockedLog, ['date' => $lockedLog->date->toDateString()]);

            return 'reverted';
        }, 3);

        if ($result === 'already_reverted') {
            return back()->with('error', 'Ky ndryshim është kthyer tashmë.');
        }
        if ($result === 'conflict') {
            return back()->with('error', 'Çmimi është ndryshuar pas këtij logu. Për siguri, ndryshimi më i ri nuk u prek.');
        }
        if ($result === 'unsafe_restore') {
            return back()->with('error', 'Çmimi i vjetër nuk është më brenda kufijve aktualë. Për siguri, nuk u kthye dhe nuk u dërgua te OTA-t.');
        }

        PushRoomTypeAri::dispatch($log->room_type_id);

        return back()->with('success', 'Çmimi u kthye — po dërgohet te OTA-t.');
    }
}
