<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantContext;
use App\Jobs\PushRoomTypeAri;
use App\Models\AuditLog;
use App\Models\PricingAutopilotLog;
use App\Models\PricingManualProtection;
use App\Models\RateOverride;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\PricingEngine;
use App\Services\PricingRulesVersion;
use App\Services\RoomPricing;
use App\Support\TenantKey;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
    use ResolvesTenantContext;

    protected $signature = 'pricing:autopilot {--days=60 : How far ahead to price} {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Auto-apply engine price suggestions within the owner\'s guardrails';

    public function handle(): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        // Fast overlap gate for normal deployments. Correctness does not rely
        // on this cache being shared: DB rule-version/type/log locks below are
        // the final cross-process authority.
        $runLock = Cache::lock(TenantKey::make('pricing:autopilot:run'), 3600);
        if (! $runLock->get()) {
            $this->warn('Autopiloti është tashmë në punë — kjo thirrje u anashkalua.');

            return self::SUCCESS;
        }

        try {
            return $this->runAutopilot();
        } finally {
            $runLock->release();
        }
    }

    private function runAutopilot(): int
    {
        if (! filter_var(Setting::get('pricing.autopilot.enabled', '0'), FILTER_VALIDATE_BOOL)) {
            $this->info('Autopilot i fikur — asgjë s\'u ndryshua.');

            return self::SUCCESS;
        }

        $from = Carbon::tomorrow();
        $to = Carbon::today()->addDays(max(1, (int) $this->option('days')));
        $applied = 0;

        $types = RoomType::query()
            ->whereHas('rooms', fn ($q) => $q->where('status', '!=', 'maintenance'))
            ->get();
        $missingBounds = $types->filter(function (RoomType $type) {
            [$min, $max] = $type->priceBounds();

            return $min === null || $max === null || $min <= 0 || $max <= 0;
        });

        if ($missingBounds->isNotEmpty()) {
            $names = $missingBounds->pluck('name')->implode(', ');
            $this->error("Autopiloti u bllokua: mungojnë kufijtë min/max për {$names}.");
            AuditLog::record('pricing.autopilot_blocked', null, [
                'reason' => 'missing_bounds',
                'room_types' => $missingBounds->pluck('id')->values()->all(),
            ]);

            return self::FAILURE;
        }

        foreach ($types as $type) {
            $rulesVersion = PricingRulesVersion::current();
            $days = PricingEngine::forRange($type, $from, $to);

            // Keep only immutable engine output here. Every mutable guard is
            // re-read under locks immediately before the write.
            $candidates = [];
            foreach ($days as $dateStr => $day) {
                if (! $day['actionable'] || $day['is_past']) {
                    continue;
                }

                $candidates[$dateStr] = (float) $day['suggested_price'];
            }

            if (! $candidates) {
                continue;
            }

            // One room type is atomic: if any later date deadlocks/fails,
            // earlier dates roll back too, so no committed batch can miss its
            // summary and OTA push. Deadlocks get two safe retries.
            $changedDates = DB::transaction(function () use ($candidates, $rulesVersion, $type) {
                // Event/strategy writes increment this DB version. If one
                // completed after the engine snapshot, discard the stale
                // candidates. Holding the row lock also blocks a rule change
                // until this batch commits.
                $lockedRulesVersion = PricingRulesVersion::lock();
                if ((int) $lockedRulesVersion->value !== $rulesVersion) {
                    return [];
                }

                // Canonical price lock order: type → logs → override.
                $lockedType = RoomType::query()->whereKey($type->id)->lockForUpdate()->first();
                if (! $lockedType) {
                    return [];
                }
                [$min, $max] = $lockedType->priceBounds();
                if ($min === null || $max === null || $min <= 0 || $max <= 0) {
                    return [];
                }

                $changedDates = [];
                foreach ($candidates as $dateStr => $suggested) {
                    $changed = DB::transaction(function () use ($dateStr, $lockedType, $max, $min, $suggested) {
                        // Lock configuration first. updateAutopilot writes these in
                        // one transaction, so a disable/guardrail change cannot be
                        // observed halfway through.
                        $settings = Setting::query()
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
                            ->get()
                            ->keyBy('key');
                        $value = fn (string $key, mixed $default = null) => $settings->get($key)?->value ?? $default;

                        if (! filter_var($value('autopilot.enabled', '0'), FILTER_VALIDATE_BOOL)) {
                            return false;
                        }

                        $pauseFrom = $value('autopilot.pause_from');
                        $pauseTo = $value('autopilot.pause_to');
                        if ($pauseFrom && $pauseTo && $dateStr >= $pauseFrom && $dateStr <= $pauseTo) {
                            return false;
                        }

                        // The first log row serializes repeated runs for this date;
                        // its effective pre-write price is the day's cap baseline.
                        $firstTodayLog = PricingAutopilotLog::query()
                            ->where('room_type_id', $lockedType->id)
                            ->whereDate('date', $dateStr)
                            ->whereDate('created_at', Carbon::today()->toDateString())
                            ->oldest('id')
                            ->lockForUpdate()
                            ->first();

                        $protection = PricingManualProtection::query()
                            ->whereDate('date', $dateStr)
                            ->where('room_type_id', $lockedType->id)
                            ->lockForUpdate()
                            ->first();

                        $protectDays = (int) $value('autopilot.protect_manual_days', 3);
                        if ($protection?->updated_at?->gte(now()->subDays($protectDays))) {
                            return false;
                        }
                        $protection?->delete();

                        $override = RateOverride::query()
                            ->whereDate('date', $dateStr)
                            ->where('room_type_id', $lockedType->id)
                            ->lockForUpdate()
                            ->first();

                        if ($override?->created_by !== null
                            && $override->updated_at?->gte(now()->subDays($protectDays))) {
                            return false;
                        }

                        $current = $override
                            ? (float) $override->price
                            : RoomPricing::seasonPrice($lockedType, $dateStr);
                        if ($current <= 0 || $current < $min || $current > $max) {
                            return false;
                        }

                        $dailyBaseline = $firstTodayLog
                            ? (float) ($firstTodayLog->effective_old_price
                                ?? $firstTodayLog->old_price
                                ?? RoomPricing::seasonPrice($lockedType, $dateStr))
                            : $current;
                        if ($dailyBaseline <= 0) {
                            return false;
                        }

                        // Daily means cumulative from the first effective price of
                        // the day, not from the previous invocation.
                        $dailyCap = (float) $value('autopilot.daily_cap_pct', 15);
                        $target = min($suggested, round($dailyBaseline * (1 + $dailyCap / 100), 2));
                        $target = max($target, round($dailyBaseline * (1 - $dailyCap / 100), 2));
                        $target = min($target, $max);
                        $target = max($target, $min);

                        $materiality = (float) $value('autopilot.materiality_pct', 5);
                        if (abs($target / $current - 1) * 100 < $materiality) {
                            return false;
                        }

                        $base = (float) $lockedType->base_price;
                        if ($base > 0 && ($target < $base * 0.25 || $target > $base * 4)) {
                            return false;
                        }

                        $old = $override?->price;
                        $override ??= new RateOverride(['date' => $dateStr, 'room_type_id' => $lockedType->id]);
                        $override->price = $target;
                        $override->created_by = null; // NULL = autopilot/system, not the owner's hand
                        $override->save();

                        PricingAutopilotLog::create([
                            'room_type_id' => $lockedType->id,
                            'date' => $dateStr,
                            'old_price' => $old,
                            'effective_old_price' => $current,
                            'new_price' => $target,
                        ]);

                        return true;
                    });

                    if ($changed) {
                        $changedDates[] = $dateStr;
                    }
                }

                if ($changedDates) {
                    AuditLog::record('pricing.autopilot_apply', $type, [
                        'count' => count($changedDates), 'dates' => $changedDates,
                    ]);
                }

                return $changedDates;
            }, 3);

            if ($changedDates) {
                PushRoomTypeAri::dispatch($type->id);
                $applied += count($changedDates);
            }
        }

        $this->info("Autopilot: {$applied} çmime u aplikuan.");

        return self::SUCCESS;
    }
}
