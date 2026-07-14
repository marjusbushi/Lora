<?php

namespace App\Console\Commands;

use App\Models\CleaningTask;
use App\Console\Concerns\ResolvesTenantContext;
use Illuminate\Console\Command;

/**
 * Nightly: archive inspected cleaning tasks so the housekeeping board shows only
 * live work. Archived rows keep all their data (archived_at is set, nothing deleted)
 * so they remain available for future reports. Scheduled ->daily() in bootstrap/app.php.
 */
class ArchiveInspectedCleaningTasks extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'housekeeping:archive-inspected {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Archive inspected cleaning tasks (drop them off the board; keep the rows for records)';

    public function handle(): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        $count = CleaningTask::where('status', 'inspected')
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);

        $this->info("U arkivuan {$count} detyra te inspektuara.");

        return self::SUCCESS;
    }
}
