<?php

namespace App\Console;

use App\Models\Tenant;
use App\Services\ChannexConfiguration;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class TenantCommandRunner
{
    public function __construct(private readonly TenantContext $context) {}

    public function run(string $command, array $parameters = [], bool $requiresChannex = false): void
    {
        Tenant::query()->active()->orderBy('id')->each(function (Tenant $tenant) use ($command, $parameters, $requiresChannex) {
            $this->context->run($tenant, function () use ($tenant, $command, $parameters, $requiresChannex) {
                if ($requiresChannex && ! app(ChannexConfiguration::class)->configured()) {
                    return;
                }

                $exitCode = Artisan::call($command, $parameters);
                if ($exitCode !== 0) {
                    throw new RuntimeException("Command {$command} failed for tenant {$tenant->id} with exit code {$exitCode}.");
                }
            });
        });
    }
}
