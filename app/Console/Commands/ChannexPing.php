<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesTenantContext;
use App\Services\ChannexClient;
use Illuminate\Console\Command;

/**
 * Smoke test: confirm the app can reach Channex with the configured API key by
 * listing the account's properties. Run on the server after CHANNEX_API_KEY is
 * set: `php artisan channex:ping`.
 */
class ChannexPing extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'channex:ping {--tenant= : ID e hotelit — i detyrueshëm për ekzekutim manual}';

    protected $description = 'Verify the Channex connection by listing the account properties';

    public function handle(): int
    {
        if (! $this->ensureTenantContext()) {
            return self::FAILURE;
        }

        // Resolved AFTER the tenant context is set: constructor injection would
        // build the client before handle() runs, making per-tenant credentials
        // (tenant_integrations) invisible and falling back to the global .env.
        $channex = app(ChannexClient::class);

        if (! $channex->configured()) {
            $this->error('CHANNEX_API_KEY is not set (.env) — cannot reach Channex.');

            return self::FAILURE;
        }

        try {
            $properties = $channex->getProperties();
        } catch (\Throwable $e) {
            // Don't echo the raw exception: a transport error message can carry
            // the request headers (incl. the API key). Log it, show a safe line.
            report($e);
            $this->error('Channex request failed — check the API key / connection (see logs).');

            return self::FAILURE;
        }

        if ($properties === []) {
            $this->warn('Connected, but no properties returned — check the API key / property access.');

            return self::SUCCESS;
        }

        $this->info('Channex connection OK. Properties on this account:');
        foreach ($properties as $p) {
            $this->line(sprintf('  - %s  [%s]', $p['attributes']['title'] ?? '(no title)', $p['id'] ?? '?'));
        }

        return self::SUCCESS;
    }
}
