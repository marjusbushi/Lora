<?php

namespace App\Console;

use App\Models\Tenant;
use App\Services\ChannexConfiguration;
use App\Services\TenantBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class TenantCommandRunner
{
    public function __construct(private readonly TenantContext $context) {}

    public function run(
        string $command,
        array $parameters = [],
        bool $requiresChannex = false,
        ?string $requiredModule = null,
    ): void {
        Tenant::query()->active()->orderBy('id')->each(function (Tenant $tenant) use ($command, $parameters, $requiresChannex, $requiredModule) {
            $this->context->run($tenant, function () use ($tenant, $command, $parameters, $requiresChannex, $requiredModule) {
                if ($requiredModule && ! app(TenantBillingService::class)->enabled($requiredModule, $tenant)) {
                    return;
                }

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
