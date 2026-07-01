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
            'roomTypes' => $types->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            'settings' => SmartPricing::settings(),
            'currency' => Setting::get('financial.default_currency_symbol', '€'),
            'aiConfigured' => AiPricing::configured(),
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

    /** AI Pricing Assistant: generate a reasoned plan for a month (returns JSON). */
    public function aiPlan(Request $request)
    {
        if (!AiPricing::configured()) {
            return response()->json(['error' => 'Asistenti AI nuk është konfiguruar. Shto çelësin Gemini te Settings → Asistenti AI.'], 422);
        }

        $data = $request->validate([
            'month' => ['required', 'date'],
            'events' => ['array', 'max:20'],
            'events.*' => ['string', 'max:200'],
        ]);

        $from = Carbon::parse($data['month'])->startOfMonth();
        $to = $from->copy()->endOfMonth();

        try {
            $plan = AiPricing::plan($from, $to, $data['events'] ?? []);
        } catch (\Throwable $e) {
            report($e);

            // GeminiClient throws owner-safe Albanian messages; strip any stray key= just in case.
            $msg = preg_replace('/key=[A-Za-z0-9._\-]+/', 'key=***', $e->getMessage());
            $msg = trim(mb_strimwidth((string) $msg, 0, 200, '…'));

            return response()->json(['error' => $msg !== '' ? $msg : "Asistenti AI s'u përgjigj. Provoni përsëri."], 502);
        }

        return response()->json($plan);
    }

    /** Apply one AI recommendation: write the suggested price for each date in the range × each room type. */
    public function applyPlan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'prices' => ['required', 'array', 'min:1', 'max:20'],
            'prices.*.room_type_id' => ['required', 'exists:room_types,id'],
            'prices.*.suggested' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
        ]);

        $from = Carbon::parse($data['date_from'])->startOfDay();
        $to = Carbon::parse($data['date_to'])->startOfDay();
        // NB: $from->diffInDays($to) — arg order matters. In Carbon 3 the reverse returns a
        // negative number, so the cap would silently never fire and let a far date write
        // thousands of overrides + flood the OTA. Keep $from first.
        if ($from->diffInDays($to) > 62) {
            return back()->with('error', 'Intervali është shumë i gjatë (maksimumi ~2 muaj).');
        }

        // Reject any suggested price wildly off the allowed band (AI hallucination / typo)
        // before it can be written and pushed to Booking.com etc.
        $types = RoomType::whereIn('id', collect($data['prices'])->pluck('room_type_id'))
            ->get()->keyBy('id');
        foreach ($data['prices'] as $p) {
            if ($this->priceOutOfBand((float) $p['suggested'], $types->get($p['room_type_id']))) {
                return back()->with('error', "Çmimi i sugjeruar {$p['suggested']} është jashtë kufijve të lejuar. Nuk u aplikua.");
            }
        }

        // Write the whole batch atomically: either all dates/types commit or none, so a mid-loop
        // failure can't leave half-applied overrides (and dispatch pushes for a partial set).
        $typeIds = [];
        DB::transaction(function () use ($from, $to, $data, &$typeIds) {
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                foreach ($data['prices'] as $p) {
                    $override = RateOverride::whereDate('date', $d->toDateString())
                        ->where('room_type_id', $p['room_type_id'])->first()
                        ?? new RateOverride(['date' => $d->toDateString(), 'room_type_id' => $p['room_type_id']]);
                    $override->price = $p['suggested'];
                    $override->created_by = auth()->id();
                    $override->save();
                    $typeIds[$p['room_type_id']] = true;
                }
            }
        });

        AuditLog::record('pricing.ai_apply', null, [
            'from' => $data['date_from'], 'to' => $data['date_to'], 'types' => array_keys($typeIds),
        ]);
        foreach (array_keys($typeIds) as $tid) {
            PushRoomTypeAri::dispatch((int) $tid);
        }

        return back()->with('success', 'Plani u aplikua për këto data.');
    }
}
