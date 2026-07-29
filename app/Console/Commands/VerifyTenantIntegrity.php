<?php

namespace App\Console\Commands;

use App\Services\TenantIntegrityAuditor;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

class VerifyTenantIntegrity extends Command
{
    protected $signature = 'tenants:verify-integrity
                            {--snapshot= : Write a PII-free counts/totals baseline to this JSON file}
                            {--compare= : Compare current counts/totals with this JSON baseline}
                            {--verify-storage : Verify every supported database file reference exists on its local/public disk}
                            {--allow-additive-schema : Allow new tables and permission/role growth while preserving every existing count/total}
                            {--allow-additive-settings : Allow setting rows to grow for existing tenants while preserving every other count/total}';

    protected $description = 'Fail if tenant ownership or same-tenant relations are invalid';

    public function handle(TenantIntegrityAuditor $auditor): int
    {
        if ($this->option('snapshot') && $this->option('compare')) {
            $this->error('Use either --snapshot or --compare, not both.');

            return self::INVALID;
        }

        $allowAdditiveSchema = (bool) $this->option('allow-additive-schema');
        $allowAdditiveSettings = (bool) $this->option('allow-additive-settings');

        if (($allowAdditiveSchema || $allowAdditiveSettings) && ! $this->option('compare')) {
            $this->error('Additive options require --compare.');

            return self::INVALID;
        }

        $violations = $auditor->violations();
        if ($this->option('verify-storage')) {
            array_push($violations, ...$auditor->storageViolations());
        }
        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->error($violation);
            }

            return self::FAILURE;
        }

        try {
            $snapshot = $auditor->snapshot();

            if ($path = $this->option('snapshot')) {
                $this->writeSnapshot((string) $path, $snapshot);
                $this->info("Tenant integrity passed; baseline written to {$path}.");

                return self::SUCCESS;
            }

            if ($path = $this->option('compare')) {
                $baseline = $this->readSnapshot((string) $path);
                if ($allowAdditiveSchema || $allowAdditiveSettings) {
                    $changes = $this->baselineChanges(
                        $baseline,
                        $snapshot,
                        allowAdditiveSchema: $allowAdditiveSchema,
                        allowAdditiveSettings: $allowAdditiveSettings,
                    );
                    if ($changes !== []) {
                        foreach ($changes as $change) {
                            $this->error("Baseline value changed: {$change}");
                        }

                        return self::FAILURE;
                    }

                    $this->info($allowAdditiveSettings
                        ? 'Tenant integrity passed; existing data and financial totals are preserved (approved additive schema/settings allowed).'
                        : 'Tenant integrity passed; existing counts and financial totals are unchanged (additive schema allowed).');

                    return self::SUCCESS;
                }

                if ($baseline !== $snapshot) {
                    $this->error('Tenant counts or financial totals changed from the baseline.');

                    return self::FAILURE;
                }

                $this->info('Tenant integrity passed; counts and financial totals are unchanged.');

                return self::SUCCESS;
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Tenant integrity passed.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $snapshot */
    private function writeSnapshot(string $path, array $snapshot): void
    {
        $directory = dirname($path);
        $pathExists = file_exists($path) || is_link($path);
        if ($pathExists && (is_link($path) || ! is_file($path) || ! is_writable($path))) {
            throw new RuntimeException("Snapshot target is not a writable regular file: {$path}");
        }
        if (! $pathExists && (! is_dir($directory) || ! is_writable($directory))) {
            throw new RuntimeException("Snapshot directory is not writable: {$directory}");
        }

        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json."\n", LOCK_EX) === false) {
            throw new RuntimeException("Could not write snapshot: {$path}");
        }
    }

    /** @return array<string, mixed> */
    private function readSnapshot(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Snapshot is not readable: {$path}");
        }

        try {
            $snapshot = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Snapshot is invalid JSON: {$path}", previous: $exception);
        }

        if (! is_array($snapshot)) {
            throw new RuntimeException("Snapshot has an invalid structure: {$path}");
        }

        return $snapshot;
    }

    /**
     * Compare every baseline leaf while allowing new keys in the current
     * snapshot. Permission rows may grow with additive schema changes. Setting
     * counts may grow only when explicitly approved, and neither may shrink.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function baselineChanges(
        array $baseline,
        array $current,
        string $prefix = '',
        bool $allowAdditiveSchema = true,
        bool $allowAdditiveSettings = false,
    ): array {
        $changes = [];

        foreach ($baseline as $key => $expected) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (! array_key_exists($key, $current)) {
                $changes[] = $path;

                continue;
            }

            $actual = $current[$key];

            // Role-system evolution (new permissions, new provisioned roles)
            // may only ever GROW under an approved additive migration.
            if ($allowAdditiveSchema && in_array($path, ['central_counts.permissions', 'central_counts.roles'], true)) {
                if (! is_int($expected) || ! is_int($actual) || $actual < $expected) {
                    $changes[] = $path;
                }

                continue;
            }

            if ($allowAdditiveSettings
                && str_starts_with($path, 'tenant_counts.settings.')
                && is_int($expected)
                && is_int($actual)
                && $actual >= $expected) {
                continue;
            }

            if (is_array($expected)) {
                if (! is_array($actual)) {
                    $changes[] = $path;

                    continue;
                }

                array_push($changes, ...$this->baselineChanges(
                    $expected,
                    $actual,
                    $path,
                    $allowAdditiveSchema,
                    $allowAdditiveSettings,
                ));

                // Only these two maps may gain new top-level metrics/tables
                // during an additive migration. New tenant IDs or dimensions
                // inside an existing table/metric remain data changes.
                if (! $allowAdditiveSchema || ! in_array($path, ['tenant_counts', 'financial_totals'], true)) {
                    foreach (array_keys(array_diff_key($actual, $expected)) as $addedKey) {
                        $changes[] = "{$path}.{$addedKey}";
                    }
                }

                continue;
            }

            if ($actual !== $expected) {
                $changes[] = $path;
            }
        }

        return $changes;
    }
}
