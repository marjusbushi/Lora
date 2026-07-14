<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupExporter;
use Illuminate\Console\Command;
use Throwable;

class ExportDatabaseBackup extends Command
{
    protected $signature = 'backups:export-database {path : Absolute output path for the SQL dump}';

    protected $description = 'Create a transaction-consistent MySQL dump without exposing credentials';

    public function handle(DatabaseBackupExporter $exporter): int
    {
        $path = (string) $this->argument('path');

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $this->error('The backup path must be absolute.');

            return self::INVALID;
        }

        try {
            $exporter->export($path);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Database backup created successfully.');

        return self::SUCCESS;
    }
}
