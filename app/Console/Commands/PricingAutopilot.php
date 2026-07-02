<?php

namespace App\Console\Commands;

use App\Jobs\PushRoomTypeAri;
use App\Models\AuditLog;
use App\Models\PricingAutopilotLog;
use App\Models\RateOverride;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\PricingEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Autopilot me kufij" — auto-accepts the deterministic engine's suggestions,
 * but ONLY when every guard passes (Lighthouse Custom Autopilot pattern):
 * owner min/max (engine-clamped) + base sanity band, materiality threshold,
 * daily change cap, seasonal pause window, and manual-override protection
 * (the owner's hand always wins). OFF by default; every change is logged and
 * revertible with one tap. Runs at 03:45 — after the 03:30 snapshot, before
 * the 04:00 ARI safety push.
 */
class PricingAutopilot extends Command
{
    protected $signature = 'pricing:autopilot {--days=60 : How far ahead to price}';

    protected $description = 'Auto-apply engine price suggestions within the owner\'s guardrails';

    public function handle(): int
    {
        if (! filter_var(Setting::get('pricing.autopilot.enabled', '0'), FILTER_VALIDATE_BOOL)) {
            $this->info('Autopilot i fikur — asgjë s\'u ndryshua.');

            return self::SUCCESS;
        }

        $materiality = (float) Setting::get('pricing.autopilot.materiality_pct', 5);
        $dailyCap = (float) Setting::get('pricing.autopilot.daily_cap_pct', 15);
        $protectDays = (int) Setting::get('pricing.autopilot.protect_manual_days', 3);
        $pauseFrom = Setting::get('pricing.autopilot.pause_from');
        $pauseTo = Setting::get('pricing.autopilot.pause_to');

        $from = Carbon::tomorrow();
        $to = Carbon::today()->addDays(max(1, (int) $this->option('days')));
        $applied = 0;

        foreach (RoomType::all() as $type) {
            $days = PricingEngine::forRange($type, $from, $to);

            // The owner's recent manual prices are untouchable.
            $protected = RateOverride::where('room_type_id', $type->id)
                ->whereNotNull('created_by')
                ->where('updated_at', '>=', now()->subDays($protectDays))
                ->get()
                ->map(fn ($o) => $o->date->toDateString())
                ->flip();

            $changes = [];
            foreach ($days as $dateStr => $day) {
                if (! $day['actionable'] || $day['is_past']) {
                    continue;
                }
                // Guard: seasonal pause window (e.g. peak August stays manual).
                if ($pauseFrom && $pauseTo && $dateStr >= $pauseFrom && $dateStr <= $pauseTo) {
                    continue;
                }
                // Guard: never overwrite a fresh manual price.
                if (isset($protected[$dateStr])) {
                    continue;
                }

                $current = (float) $day['current_price'];
                if ($current <= 0) {
                    continue;
                }
                // Guard: daily cap — never move a price more than ±cap% per run.
                $target = (float) $day['suggested_price'];
                $target = min($target, round($current * (1 + $dailyCap / 100), 2));
                $target = max($target, round($current * (1 - $dailyCap / 100), 2));

                // Guard: materiality — micro-changes just churn the OTAs.
                if (abs($target / $current - 1) * 100 < $materiality) {
                    continue;
                }
                // Guard: base sanity band (engine already clamps to owner min/max).
                $base = (float) $type->base_price;
                if ($base > 0 && ($target < $base * 0.25 || $target > $base * 4)) {
                    continue;
                }

                $changes[$dateStr] = $target;
            }

            if (! $changes) {
                continue;
            }

            DB::transaction(function () use ($changes, $type) {
                foreach ($changes as $dateStr => $price) {
                    $override = RateOverride::whereDate('date', $dateStr)
                        ->where('room_type_id', $type->id)->first();
                    $old = $override?->price;

                    $override ??= new RateOverride(['date' => $dateStr, 'room_type_id' => $type->id]);
                    $override->price = $price;
                    $override->created_by = null; // NULL = autopilot/system, not the owner's hand
                    $override->save();

                    PricingAutopilotLog::create([
                        'room_type_id' => $type->id,
                        'date' => $dateStr,
                        'old_price' => $old,
                        'new_price' => $price,
                    ]);
                }
                AuditLog::record('pricing.autopilot_apply', $type, [
                    'count' => count($changes), 'dates' => array_keys($changes),
                ]);
            });

            PushRoomTypeAri::dispatch($type->id);
            $applied += count($changes);
        }

        $this->info("Autopilot: {$applied} çmime u aplikuan.");

        return self::SUCCESS;
    }
}
