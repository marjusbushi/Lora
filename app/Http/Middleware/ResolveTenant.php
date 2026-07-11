<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (str_starts_with(strtolower($request->getHost()), 'www.')) {
            $canonicalHost = substr($request->getHost(), 4);
            $canonicalUrl = $request->getScheme().'://'.$canonicalHost.$request->getRequestUri();

            return redirect()->away($canonicalUrl, Response::HTTP_PERMANENTLY_REDIRECT);
        }

        // The Lora PMS product website is deliberately tenantless. Exact host
        // allow-listing prevents an unknown hotel domain from bypassing tenant
        // resolution while keeping the product homepage independent from hotel
        // data. Other routes on the same host (login/PMS) still resolve tenant.
        $tenantlessProductRoute = $request->routeIs('website.home')
            || $request->routeIs('login', 'password.*');

        if ($tenantlessProductRoute && $this->isMarketingHost($request)) {
            return $next($request);
        }

        $tenant = $this->resolve($request);

        abort_unless($tenant, 404, 'Hotel not found.');

        $this->context->set($tenant);

        try {
            return $next($request);
        } finally {
            // Queue workers and long-running runtimes must never leak one tenant
            // into the next request/job.
            $this->context->clear();
        }
    }

    private function isMarketingHost(Request $request): bool
    {
        return in_array(
            strtolower($request->getHost()),
            config('lora.marketing_hosts', []),
            true,
        );
    }

    private function resolve(Request $request): ?Tenant
    {
        $user = $request->user();
        $requestedTenantId = $request->session()->get('tenant_id');

        if ($user && $requestedTenantId) {
            $allowed = $user->is_super_admin
                || $user->activeTenants()->whereKey($requestedTenantId)->exists();

            if ($allowed) {
                return Tenant::query()->active()->find($requestedTenantId);
            }
        }

        if ($user?->current_tenant_id) {
            $allowed = $user->is_super_admin
                || $user->activeTenants()->whereKey($user->current_tenant_id)->exists();

            if ($allowed) {
                $tenant = Tenant::query()->active()->find($user->current_tenant_id);
                if ($tenant) {
                    return $tenant;
                }
            }
        }

        $domainTenant = TenantDomain::query()
            ->where('domain', strtolower($request->getHost()))
            ->with('tenant')
            ->first()?->tenant;

        if ($domainTenant?->status === 'active') {
            if (! $user || $user->is_super_admin || $user->activeTenants()->whereKey($domainTenant->id)->exists()) {
                return $domainTenant;
            }
        }

        if ($user) {
            return $user->activeTenants()->active()->orderBy('tenants.id')->first();
        }

        return null;
    }
}
