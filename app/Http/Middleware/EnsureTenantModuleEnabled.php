<?php

namespace App\Http\Middleware;

use App\Services\TenantBillingService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantModuleEnabled
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantBillingService $billing,
    ) {}

    /** Kalon nëse TENANT-i ka të aktivizuar TË PAKTËN NJËRIN nga modulet e dhëna (any-of). */
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $tenant = $this->context->tenant();

        abort_unless($tenant, 404, 'Hotel not found.');
        abort_unless(
            collect($modules)->contains(fn (string $module) => $this->billing->enabled($module, $tenant)),
            403,
            'Ky modul nuk është aktiv për këtë hotel.',
        );

        return $next($request);
    }
}
