<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class DatabaseBackupExporter
{
    public function export(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException("Backup directory is not writable: {$directory}");
        }

        if (file_exists($path)) {
            throw new RuntimeException("Refusing to overwrite an existing backup: {$path}");
        }

        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}");
        if (! is_array($connection)) {
            throw new RuntimeException("Database connection is not configured: {$connectionName}");
        }

        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Database backups require the MySQL connection.');
        }

        $database = $this->requiredString($connection, 'database');
        $username = $this->requiredString($connection, 'username');
        $password = (string) ($connection['password'] ?? '');
        $binary = (string) config('backup.mysql_dump_binary', 'mysqldump');
        $timeout = max(60, (int) config('backup.mysql_dump_timeout_seconds', 900));

        $command = [
            $binary,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--user='.$username,
        ];

        $socket = (string) ($connection['unix_socket'] ?? '');
        if ($socket !== '') {
            $command[] = '--socket='.$socket;
        } else {
            $command[] = '--host='.$this->requiredString($connection, 'host');
            $command[] = '--port='.(string) ($connection['port'] ?? 3306);
        }

        $command[] = $database;

        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException("Could not create database backup: {$path}");
        }

        @chmod($path, 0600);
        $stderr = '';

        try {
            $process = new Process($command, base_path(), ['MYSQL_PWD' => $password]);
            $process->setTimeout($timeout);
            $process->run(function (string $type, string $buffer) use ($handle, &$stderr): void {
                if ($type === Process::OUT) {
                    $this->writeAll($handle, $buffer);

                    return;
                }

                // Keep error output bounded and never include the command/environment.
                $stderr = substr($stderr.$buffer, -8192);
            });

            if (! $process->isSuccessful()) {
                $message = trim($stderr) ?: 'mysqldump exited unsuccessfully.';
                throw new RuntimeException("Database export failed: {$message}");
            }

            if (ftell($handle) === 0) {
                throw new RuntimeException('Database export produced an empty file.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);

            throw $exception;
        }

        fclose($handle);
    }

    /** @param resource $handle */
    private function writeAll($handle, string $buffer): void
    {
        $length = strlen($buffer);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($buffer, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the database backup.');
            }

            $offset += $written;
        }
    }

    /** @param array<string, mixed> $connection */
    private function requiredString(array $connection, string $key): string
    {
        $value = $connection[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Database {$key} is missing.");
        }

        return $value;
    }
}
