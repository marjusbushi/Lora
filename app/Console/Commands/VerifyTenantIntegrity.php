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
                            {--compare= : Compare current counts/totals with this JSON baseline}';

    protected $description = 'Fail if tenant ownership or same-tenant relations are invalid';

    public function handle(TenantIntegrityAuditor $auditor): int
    {
        if ($this->option('snapshot') && $this->option('compare')) {
            $this->error('Use either --snapshot or --compare, not both.');

            return self::INVALID;
        }

        $violations = $auditor->violations();
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
        if (! is_dir($directory) || ! is_writable($directory)) {
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
}
