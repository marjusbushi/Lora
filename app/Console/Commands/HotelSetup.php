<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Console\Concerns\ResolvesTenantContext;
use Illuminate\Console\Command;

class HotelSetup extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'hotel:setup {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'First-time hotel setup: run migrations, seed admin user';

    public function handle(): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        $this->info('Setting up Chanel Manager...');

        // Run migrations
        $this->call('migrate', ['--force' => true]);

        // Seed database
        if (User::count() === 0) {
            $this->call('db:seed', ['--force' => true]);
            $this->info('Admin user created: admin@chanelmanager.com / password');
        } else {
            $this->warn('Database already has users — skipping seed.');
        }

        $this->info('Setup complete!');

        return Command::SUCCESS;
    }
}
