<?php

namespace App\Services;

use App\Jobs\PushRoomTypeAri;
use App\Models\AuditLog;
use App\Models\RateOverride;
use App\Models\RoomType;
use App\Models\Season;
use App\Models\SeasonRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds and applies a deterministic, owner-confirmed season-year copy.
 *
 * Preview is read-only. Apply rebuilds the same preview while holding the
 * shared pricing-rules mutex, so an old browser tab can never copy stale
 * prices over newer owner changes.
 */
class SeasonCopyService
{
    /** @return array<string, mixed> */
    public function preview(int $sourceYear, int $targetYear, float $upliftPct): array
    {
        $rulesVersion = PricingRulesVersion::current();

        return $this->publicResponse(
            $this->buildPlan($sourceYear, $targetYear, $upliftPct, $rulesVersion),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(
        int $sourceYear,
        int $targetYear,
        float $upliftPct,
        int $expectedRulesVersion,
        string $expectedPreviewHash,
    ): array {
        $transactionResult = DB::transaction(function () use (
            $sourceYear,
            $targetYear,
            $upliftPct,
            $expectedRulesVersion,
            $expectedPreviewHash,
        ) {
            $version = PricingRulesVersion::lock();
            $currentRulesVersion = (int) $version->value;
            $plan = $this->buildPlan($sourceYear, $targetYear, $upliftPct, $currentRulesVersion);

            if (
                $expectedRulesVersion !== $currentRulesVersion
                || ! hash_equals($plan['preview_hash'], $expectedPreviewHash)
            ) {
                $plan['state'] = 'stale';

                return ['response' => $plan, 'dispatch' => null];
            }

            if ($plan['state'] === 'conflict') {
                return ['response' => $plan, 'dispatch' => null];
            }

            if ($plan['state'] === 'no_changes') {
                return ['response' => $plan, 'dispatch' => null];
            }

            $createdSeasons = 0;
            $createdRates = 0;
            $createdSeasonIds = [];
            $copiedSnapshot = [];
            foreach ($plan['_actions'] as $action) {
                if ($action['existing']) {
                    continue;
                }

                $season = Season::create([
                    'name' => $action['name'],
                    'start_date' => $action['start_date'],
                    'end_date' => $action['end_date'],
                    'priority' => $action['priority'],
                ]);
                $createdSeasons++;
                $createdSeasonIds[] = $season->id;

                $rateSnapshot = [];
                foreach ($action['rates'] as $rate) {
                    SeasonRate::create([
                        'season_id' => $season->id,
                        'room_type_id' => $rate['room_type_id'],
                        'price' => $rate['target_price'],
                    ]);
                    $createdRates++;
                    $rateSnapshot[] = [
                        'room_type_id' => $rate['room_type_id'],
                        'price' => $rate['target_price'],
                    ];
                }
                $copiedSnapshot[] = [
                    'season_id' => $season->id,
                    'name' => $action['name'],
                    'start_date' => $action['start_date'],
                    'end_date' => $action['end_date'],
                    'priority' => $action['priority'],
                    'rates' => $rateSnapshot,
                ];
            }

            // Deliberately strict (not AuditLog::record): if the audit row
            // cannot be written, the whole pricing change rolls back.
            AuditLog::query()->create([
                'causer_id' => auth()->id(),
                'action' => 'pricing.seasons_copy',
                'subject_type' => Season::class,
                'subject_id' => null,
                'properties' => [
                    'source_year' => $sourceYear,
                    'target_year' => $targetYear,
                    'uplift_pct' => $plan['uplift_pct'],
                    'rules_version_before' => $currentRulesVersion,
                    'rules_version_after' => $currentRulesVersion + 1,
                    'preview_hash' => $expectedPreviewHash,
                    'created_seasons' => $createdSeasons,
                    'created_rates' => $createdRates,
                    'created_season_ids' => $createdSeasonIds,
                    'copied_seasons' => $copiedSnapshot,
                    'target_from' => $plan['_range']['from'],
                    'target_to' => $plan['_range']['to'],
                    'rate_override_warning_count' => $plan['override_count'],
                ],
                'created_at' => now(),
            ]);

            PricingRulesVersion::increment($version);

            $plan['state'] = 'applied';
            $plan['rules_version'] = $currentRulesVersion + 1;

            return [
                'response' => $plan,
                'dispatch' => $plan['_range'],
            ];
        }, 3);

        $response = $this->publicResponse($transactionResult['response']);

        // Queue only after the database commit. The job re-checks the OTA
        // publication cutoff when it executes and clamps this narrow range.
        // If queue insertion itself fails, never tell the owner the database
        // copy failed: return an explicit sync warning so they can use
        // "Sinkronizo tani" while the nightly reconciliation remains a backup.
        if ($transactionResult['dispatch']) {
            try {
                $queued = $this->queueRateSync($transactionResult['dispatch']);
                $response['sync_queued'] = $queued > 0;
                $response['sync_queue_count'] = $queued;
            } catch (\Throwable $e) {
                report($e);
                $response['sync_queued'] = false;
                $response['sync_queue_count'] = 0;
            }
        }

        return $response;
    }

    /** @param array{from: string, to: string} $range */
    protected function queueRateSync(array $range): int
    {
        return PushRoomTypeAri::dispatchAllMapped($range['from'], $range['to']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlan(int $sourceYear, int $targetYear, float $upliftPct, int $rulesVersion): array
    {
        $upliftPct = round($upliftPct, 2);
        $conflicts = [];
        $publicSeasons = [];
        $actions = [];

        $sourceSeasons = Season::query()
            ->whereBetween('start_date', ["{$sourceYear}-01-01", "{$sourceYear}-12-31"])
            ->with('rates')
            ->orderBy('start_date')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $roomTypes = RoomType::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'base_price', 'min_price', 'max_price']);

        if ($sourceYear === $targetYear) {
            $conflicts[] = 'Viti burim dhe viti objektiv duhet të jenë të ndryshëm.';
        }
        if ($sourceSeasons->isEmpty()) {
            $conflicts[] = "Nuk u gjet asnjë sezon që fillon në vitin {$sourceYear}.";
        }
        if ($roomTypes->isEmpty()) {
            $conflicts[] = 'Nuk ka tipe aktive dhomash për të kopjuar çmimet.';
        }

        $this->detectSourceOverlaps($sourceSeasons, $conflicts);

        foreach ($sourceSeasons as $source) {
            if ($source->end_date->lt($source->start_date)) {
                $conflicts[] = "Sezoni burim \"{$source->name}\" ka datën e mbarimit para datës së fillimit.";

                continue;
            }

            $shiftedStart = $this->shiftDate($source->start_date->toImmutable(), $sourceYear, $targetYear);
            $shiftedEnd = $this->shiftDate($source->end_date->toImmutable(), $sourceYear, $targetYear);

            if (! $shiftedStart || ! $shiftedEnd) {
                $conflicts[] = "Sezoni \"{$source->name}\" përmban 29 Shkurt, por data nuk ekziston në vitin objektiv.";

                continue;
            }

            $targetName = $this->targetName($source->name, $sourceYear, $targetYear);
            $sourceRates = $source->rates->keyBy('room_type_id');
            $publicRates = [];
            $actionRates = [];

            foreach ($roomTypes as $roomType) {
                $explicitRate = $sourceRates->get($roomType->id);
                $sourcePrice = round((float) ($explicitRate?->price ?? $roomType->base_price), 2);
                $targetPrice = round($sourcePrice * (1 + $upliftPct / 100), 2);

                $this->validatePrice($roomType, $targetPrice, $targetName, $conflicts);

                $rate = [
                    'room_type_id' => $roomType->id,
                    'room_type_name' => $roomType->name,
                    'source_price' => $sourcePrice,
                    'target_price' => $targetPrice,
                    'source_kind' => $explicitRate ? 'season' : 'base',
                ];
                $publicRates[] = $rate;
                $actionRates[] = $rate;
            }

            $seasonRow = [
                'source_season_id' => $source->id,
                'source_name' => $source->name,
                'target_name' => $targetName,
                'start_date' => $shiftedStart->toDateString(),
                'end_date' => $shiftedEnd->toDateString(),
                'priority' => (int) $source->priority,
                'rates' => $publicRates,
            ];
            $publicSeasons[] = $seasonRow;
            $actions[] = [
                'name' => $targetName,
                'start_date' => $shiftedStart->toDateString(),
                'end_date' => $shiftedEnd->toDateString(),
                'priority' => (int) $source->priority,
                'rates' => $actionRates,
                'existing' => false,
            ];
        }

        [$actions, $targetConflicts] = $this->matchExistingTargets($actions);
        array_push($conflicts, ...$targetConflicts);
        $conflicts = array_values(array_unique($conflicts));

        $range = $this->targetRange($actions);
        $overrideCount = $range
            ? RateOverride::query()->whereBetween('date', [$range['from'], $range['to']])->count()
            : 0;

        $allExisting = $actions !== [] && collect($actions)->every(fn (array $action) => $action['existing']);
        $state = $conflicts !== [] ? 'conflict' : ($allExisting ? 'no_changes' : 'ready');

        $response = [
            'state' => $state,
            'source_year' => $sourceYear,
            'target_year' => $targetYear,
            'uplift_pct' => $upliftPct,
            'rules_version' => $rulesVersion,
            'seasons' => $publicSeasons,
            'conflicts' => $conflicts,
            'override_count' => $overrideCount,
            'ota_publish_until' => $this->otaPublishUntil(),
        ];
        $response['preview_hash'] = $this->previewHash($response);
        $response['_actions'] = $actions;
        $response['_range'] = $range;

        return $response;
    }

    /** @param Collection<int, Season> $seasons */
    private function detectSourceOverlaps(Collection $seasons, array &$conflicts): void
    {
        $values = $seasons->values();
        for ($left = 0; $left < $values->count(); $left++) {
            for ($right = $left + 1; $right < $values->count(); $right++) {
                $a = $values[$left];
                $b = $values[$right];
                if (
                    (int) $a->priority === (int) $b->priority
                    && $this->datesOverlap(
                        $a->start_date->toDateString(),
                        $a->end_date->toDateString(),
                        $b->start_date->toDateString(),
                        $b->end_date->toDateString(),
                    )
                ) {
                    $conflicts[] = "Sezonet burim \"{$a->name}\" dhe \"{$b->name}\" mbivendosen me të njëjtin prioritet.";
                }
            }
        }
    }

    private function shiftDate(CarbonImmutable $date, int $sourceYear, int $targetYear): ?CarbonImmutable
    {
        $shiftedYear = $targetYear + ($date->year - $sourceYear);
        if (! checkdate($date->month, $date->day, $shiftedYear)) {
            return null;
        }

        return CarbonImmutable::create(
            $shiftedYear,
            $date->month,
            $date->day,
            0,
            0,
            0,
            config('app.timezone'),
        );
    }

    private function targetName(string $sourceName, int $sourceYear, int $targetYear): string
    {
        $pattern = '/(?<!\d)'.preg_quote((string) $sourceYear, '/').'(?!\d)/u';
        $replaced = preg_replace($pattern, (string) $targetYear, $sourceName, -1, $count);

        return $count > 0
            ? (string) $replaced
            : trim($sourceName).' '.$targetYear;
    }

    private function validatePrice(RoomType $roomType, float $targetPrice, string $seasonName, array &$conflicts): void
    {
        $min = $roomType->min_price !== null ? (float) $roomType->min_price : null;
        $max = $roomType->max_price !== null ? (float) $roomType->max_price : null;

        if ($min !== null && $max !== null && $min > $max) {
            $conflicts[] = "Tipi \"{$roomType->name}\" ka kufij min/max të pavlefshëm.";

            return;
        }
        if ($targetPrice <= 0) {
            $conflicts[] = "Çmimi i \"{$roomType->name}\" në \"{$seasonName}\" duhet të jetë mbi 0.";
        } elseif ($min !== null && $targetPrice < $min) {
            $conflicts[] = "Çmimi €{$this->money($targetPrice)} i \"{$roomType->name}\" në \"{$seasonName}\" është nën minimumin €{$this->money($min)}.";
        } elseif ($max !== null && $targetPrice > $max) {
            $conflicts[] = "Çmimi €{$this->money($targetPrice)} i \"{$roomType->name}\" në \"{$seasonName}\" kalon maksimumin €{$this->money($max)}.";
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function matchExistingTargets(array $actions): array
    {
        $range = $this->targetRange($actions);
        if (! $range) {
            return [$actions, []];
        }

        $existing = Season::query()
            ->whereDate('start_date', '<=', $range['to'])
            ->whereDate('end_date', '>=', $range['from'])
            ->with('rates')
            ->orderBy('id')
            ->get();

        $exactIds = [];
        $conflicts = [];
        foreach ($actions as $index => $action) {
            $matches = $existing->filter(fn (Season $candidate) => $this->isExactTarget($candidate, $action));
            if ($matches->count() > 1) {
                $conflicts[] = "Ekzistojnë disa kopje identike të sezonit \"{$action['name']}\"; pastro duplikatet para kopjimit.";
            } elseif ($matches->count() === 1) {
                $id = (int) $matches->first()->id;
                $actions[$index]['existing'] = true;
                $exactIds[$id] = true;
            }
        }

        foreach ($actions as $action) {
            foreach ($existing as $candidate) {
                if (isset($exactIds[(int) $candidate->id])) {
                    continue;
                }
                if ($this->datesOverlap(
                    $action['start_date'],
                    $action['end_date'],
                    $candidate->start_date->toDateString(),
                    $candidate->end_date->toDateString(),
                )) {
                    $conflicts[] = "Sezoni objektiv \"{$action['name']}\" mbivendoset me sezonin ekzistues \"{$candidate->name}\" ({$candidate->start_date->toDateString()} – {$candidate->end_date->toDateString()}).";
                }
            }
        }

        return [$actions, array_values(array_unique($conflicts))];
    }

    /** @param array<string, mixed> $action */
    private function isExactTarget(Season $candidate, array $action): bool
    {
        if (
            $candidate->name !== $action['name']
            || $candidate->start_date->toDateString() !== $action['start_date']
            || $candidate->end_date->toDateString() !== $action['end_date']
            || (int) $candidate->priority !== $action['priority']
        ) {
            return false;
        }

        $expected = collect($action['rates'])
            ->mapWithKeys(fn (array $rate) => [(int) $rate['room_type_id'] => $this->money($rate['target_price'])])
            ->sortKeys()
            ->all();
        $actual = $candidate->rates
            ->mapWithKeys(fn (SeasonRate $rate) => [(int) $rate->room_type_id => $this->money((float) $rate->price)])
            ->sortKeys()
            ->all();

        return $actual === $expected;
    }

    private function datesOverlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @return array{from: string, to: string}|null
     */
    private function targetRange(array $actions): ?array
    {
        if ($actions === []) {
            return null;
        }

        return [
            'from' => collect($actions)->min('start_date'),
            'to' => collect($actions)->max('end_date'),
        ];
    }

    private function otaPublishUntil(): ?string
    {
        if (class_exists(OtaSellWindow::class)) {
            return app(OtaSellWindow::class)->effectiveUntil()->toDateString();
        }

        return CarbonImmutable::today(config('app.timezone'))->addDays(365)->toDateString();
    }

    /** @param array<string, mixed> $response */
    private function previewHash(array $response): string
    {
        return hash('sha256', json_encode(
            $response,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /** @param array<string, mixed> $response */
    private function publicResponse(array $response): array
    {
        unset($response['_actions'], $response['_range']);

        return $response;
    }
}
