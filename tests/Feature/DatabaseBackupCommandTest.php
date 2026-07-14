<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DatabaseBackupCommandTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/lora-backup-test-'.bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);

        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => 'db.internal',
            'port' => 3307,
            'database' => 'lora_test',
            'username' => 'backup_user',
            'password' => 'fake-secret-for-test',
            'unix_socket' => '',
        ]);
    }

    protected function tearDown(): void
    {
        putenv('FAKE_ARGS_FILE');
        putenv('FAKE_PASSWORD_FILE');
        unset($_ENV['FAKE_ARGS_FILE'], $_ENV['FAKE_PASSWORD_FILE']);

        if (is_dir($this->temporaryDirectory)) {
            foreach (glob($this->temporaryDirectory.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_it_exports_mysql_without_putting_the_password_in_arguments(): void
    {
        $argumentsPath = $this->temporaryDirectory.'/arguments';
        $passwordPath = $this->temporaryDirectory.'/password';
        $binary = $this->fakeBinary(<<<'BASH'
printf '%s\n' "$@" > "$FAKE_ARGS_FILE"
printf '%s' "$MYSQL_PWD" > "$FAKE_PASSWORD_FILE"
printf '%s\n' '-- consistent mysql dump'
BASH);

        putenv("FAKE_ARGS_FILE={$argumentsPath}");
        putenv("FAKE_PASSWORD_FILE={$passwordPath}");
        $_ENV['FAKE_ARGS_FILE'] = $argumentsPath;
        $_ENV['FAKE_PASSWORD_FILE'] = $passwordPath;
        config()->set('backup.mysql_dump_binary', $binary);

        $output = $this->temporaryDirectory.'/database.sql';
        $exitCode = Artisan::call('backups:export-database', ['path' => $output]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame("-- consistent mysql dump\n", file_get_contents($output));
        $this->assertSame('fake-secret-for-test', file_get_contents($passwordPath));
        $this->assertStringContainsString('--single-transaction', file_get_contents($argumentsPath));
        $this->assertStringContainsString('--host=db.internal', file_get_contents($argumentsPath));
        $this->assertStringContainsString('--port=3307', file_get_contents($argumentsPath));
        $this->assertStringContainsString('lora_test', file_get_contents($argumentsPath));
        $this->assertStringNotContainsString('fake-secret-for-test', file_get_contents($argumentsPath));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($output)), -4));
    }

    public function test_it_removes_a_partial_dump_when_mysqldump_fails(): void
    {
        $binary = $this->fakeBinary(<<<'BASH'
printf '%s\n' '-- partial output'
printf '%s\n' 'simulated export failure' >&2
exit 2
BASH);
        config()->set('backup.mysql_dump_binary', $binary);

        $output = $this->temporaryDirectory.'/database.sql';
        $exitCode = Artisan::call('backups:export-database', ['path' => $output]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('simulated export failure', Artisan::output());
        $this->assertFileDoesNotExist($output);
    }

    public function test_it_rejects_relative_and_existing_paths(): void
    {
        $this->assertSame(2, Artisan::call('backups:export-database', ['path' => 'database.sql']));

        $output = $this->temporaryDirectory.'/database.sql';
        file_put_contents($output, 'keep me');
        config()->set('backup.mysql_dump_binary', '/does/not/matter');

        $this->assertSame(1, Artisan::call('backups:export-database', ['path' => $output]));
        $this->assertSame('keep me', file_get_contents($output));
    }

    private function fakeBinary(string $body): string
    {
        $path = $this->temporaryDirectory.'/mysqldump-'.bin2hex(random_bytes(4));
        file_put_contents($path, "#!/usr/bin/env bash\nset -eu\n{$body}\n");
        chmod($path, 0700);

        return $path;
    }
}
