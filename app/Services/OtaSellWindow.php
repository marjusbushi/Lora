<?php

namespace App\Services;

use App\Models\ChannelMapping;
use App\Models\Setting;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for the dates that may be sold through Channex.
 *
 * Older installations have no setting. In that case the historical rolling
 * window remains unchanged: today through today + 365 days, inclusive.
 */
class OtaSellWindow
{
    public const SELL_UNTIL_KEY = 'channex.sell_until_date';

    public const VERSION_KEY = 'channex.sell_window_version';

    public const APPLIED_UNTIL_KEY = 'channex.sell_window_applied_until';

    public const MAX_PUBLISHED_KEY = 'channex.sell_window_max_published_date';

    /** Every ARI writer and sell-window update uses this cross-process mutex. */
    public const ARI_LOCK = 'channex:ari:write';

    public const ARI_LOCK_SECONDS = 120;

    public const DEFAULT_DAYS = 365;

    /** Channex defaults inventory state to 500 days and caps it at 730. */
    public const DEFAULT_STATE_LENGTH_DAYS = 500;

    public const MAX_STATE_LENGTH_DAYS = 730;

    public function __construct(private readonly ChannexConfiguration $configuration) {}

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::today();
    }

    public function defaultUntil(): CarbonImmutable
    {
        return $this->today()->addDays(self::DEFAULT_DAYS);
    }

    public function maxDays(): int
    {
        return min(
            self::MAX_STATE_LENGTH_DAYS,
            max(100, (int) $this->configuration->get('state_length_days', self::DEFAULT_STATE_LENGTH_DAYS)),
        );
    }

    public function maxUntil(): CarbonImmutable
    {
        // state_length counts inventory rows and includes today.
        return $this->today()->addDays($this->maxDays() - 1);
    }

    public function configuredUntil(): ?CarbonImmutable
    {
        return $this->dateSetting(self::SELL_UNTIL_KEY);
    }

    public function effectiveUntil(): CarbonImmutable
    {
        return $this->configuredUntil() ?? $this->defaultUntil();
    }

    public function appliedUntil(): ?CarbonImmutable
    {
        return $this->dateSetting(self::APPLIED_UNTIL_KEY);
    }

    public function version(): int
    {
        return max(0, (int) Setting::get(self::VERSION_KEY, 0));
    }

    /** Furthest date that may already contain ARI values at Channex. */
    public function knownHorizon(): CarbonImmutable
    {
        $dates = array_filter([
            $this->configuredUntil(),
            $this->appliedUntil(),
            $this->dateSetting(self::MAX_PUBLISHED_KEY),
        ]);

        // Before the first explicit setting, preserve/remember the legacy
        // rolling horizon because those dates may already be open at Channex.
        if ($this->configuredUntil() === null) {
            $dates[] = $this->defaultUntil();
        }

        return collect($dates)->reduce(
            fn (?CarbonImmutable $max, CarbonImmutable $date) => $max === null || $date->gt($max) ? $date : $max,
            $this->effectiveUntil(),
        );
    }

    /**
     * Normalize the legacy/default request semantics before cutoff clamping.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function requestedRange(CarbonInterface|string|null $from, CarbonInterface|string|null $to): array
    {
        $start = $from ? CarbonImmutable::parse($from)->startOfDay() : $this->today();
        $requestedEnd = $to
            ? CarbonImmutable::parse($to)->startOfDay()
            : ($from ? $start->addDays(self::DEFAULT_DAYS) : $this->defaultUntil());

        return [$start, $requestedEnd];
    }

    /**
     * Clamp any requested execution-time range to today .. configured cutoff.
     * Null means that the whole request falls outside the OTA sell window.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function clamp(CarbonInterface|string|null $from, CarbonInterface|string|null $to): ?array
    {
        [$start, $requestedEnd] = $this->requestedRange($from, $to);
        $start = $start->max($this->today());
        $end = $requestedEnd->min($this->effectiveUntil());

        return $start->lte($end) ? [$start, $end] : null;
    }

    /**
     * Serialize ARI writes and configuration changes. Waiting callers fail
     * loudly after 30 seconds so their queue job can retry instead of racing.
     */
    public function withAriLock(Closure $callback): mixed
    {
        $tenantId = app(TenantContext::class)->id() ?? 'global';

        return Cache::lock(self::ARI_LOCK.':'.$tenantId, self::ARI_LOCK_SECONDS)->block(30, $callback);
    }

    /**
     * Summary consumed by the pricing/settings screen.
     *
     * @return array{configured_until:?string,effective_until:string,default_until:string,applied_until:?string,version:int,min_date:string,max_date:string,max_days:int,room_type_count:int}
     */
    public function summary(): array
    {
        return [
            'configured_until' => $this->configuredUntil()?->toDateString(),
            'effective_until' => $this->effectiveUntil()->toDateString(),
            'default_until' => $this->defaultUntil()->toDateString(),
            'applied_until' => $this->appliedUntil()?->toDateString(),
            'version' => $this->version(),
            'min_date' => $this->today()->toDateString(),
            'max_date' => $this->maxUntil()->toDateString(),
            'max_days' => $this->maxDays(),
            'room_type_count' => ChannelMapping::query()
                ->where('channel', 'channex')
                ->whereNotNull('channex_room_type_id')
                ->whereHas('roomType')
                ->distinct()
                ->count('room_type_id'),
        ];
    }

    /** Initialize and row-lock the monotonic revision. Call inside a DB transaction. */
    public function lockVersion(): Setting
    {
        $query = Setting::query()
            ->where('group', 'channex')
            ->where('key', 'sell_window_version');

        $row = (clone $query)->lockForUpdate()->first();
        if ($row) {
            return $row;
        }

        Setting::query()->insertOrIgnore([
            'tenant_id' => app(TenantContext::class)->idOrDefault(),
            'group' => 'channex',
            'key' => 'sell_window_version',
            'value' => '0',
            'type' => 'number',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = (clone $query)->lockForUpdate()->first();
        if (! $row) {
            throw new \RuntimeException('OTA sell-window version could not be initialized.');
        }

        return $row;
    }

    /** Store the furthest successfully published date without moving it back. */
    public function rememberPublishedThrough(CarbonInterface $date): void
    {
        $candidate = CarbonImmutable::parse($date)->startOfDay();
        $known = $this->dateSetting(self::MAX_PUBLISHED_KEY);
        if ($known === null || $candidate->gt($known)) {
            Setting::set(self::MAX_PUBLISHED_KEY, $candidate->toDateString());
        }
    }

    /** Mark a reconciliation only when its revision is still current. */
    public function markAppliedIfCurrent(int $expectedVersion, CarbonInterface $target): bool
    {
        return $this->withAriLock(function () use ($expectedVersion, $target) {
            if ($this->version() !== $expectedVersion
                || ! $this->effectiveUntil()->isSameDay($target)) {
                return false;
            }

            Setting::set(self::APPLIED_UNTIL_KEY, CarbonImmutable::parse($target)->toDateString());
            $this->rememberPublishedThrough($this->knownHorizon());

            return true;
        });
    }

    private function dateSetting(string $key): ?CarbonImmutable
    {
        [$group, $settingKey] = explode('.', $key, 2);
        $row = Setting::query()
            ->where('group', $group)
            ->where('key', $settingKey)
            ->first();
        if (! $row) {
            return null;
        }

        try {
            $value = (string) $row->value;
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if ($date->toDateString() !== $value) {
                throw new \RuntimeException('Date was normalized.');
            }

            return $date;
        } catch (\Throwable $e) {
            // Fail closed. Treating corruption as an absent setting would fall
            // back to the rolling +365 window and could reopen OTA inventory.
            throw new \RuntimeException("Invalid date stored in {$key}; OTA ARI push stopped.", 0, $e);
        }
    }
}
