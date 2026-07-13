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

        $productHome = $request->routeIs('website.home')
            && ($this->isMarketingHost($request) || $this->isDedicatedControlPanelHost($request));
        $productAuth = ($request->is('login')
            || $request->routeIs('login', 'logout', 'password.*', 'verification.*'))
            && ($this->isMarketingHost($request) || $this->isControlPanelHost($request));
        $controlPlane = $request->routeIs('super-admin.*')
            && $this->isControlPanelHost($request);

        if ($productHome || $productAuth || $controlPlane) {
            $this->context->clear();

            try {
                return $next($request);
            } finally {
                $this->context->clear();
            }
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

    private function isControlPanelHost(Request $request): bool
    {
        return in_array(
            strtolower($request->getHost()),
            config('lora.control_panel_hosts', []),
            true,
        );
    }

    private function isDedicatedControlPanelHost(Request $request): bool
    {
        return in_array(
            strtolower($request->getHost()),
            config('lora.dedicated_control_panel_hosts', []),
            true,
        );
    }

    private function resolve(Request $request): ?Tenant
    {
        // Public surfaces — the guest website, booking engine, and external
        // webhooks — always belong to the host that was called. A visitor's
        // login (or a super admin's tenant switch) must never move a public
        // page, a booking, or a webhook onto another hotel.
        if ($request->routeIs('website.*', 'channex.webhook')) {
            return $this->resolveFromDomain($request);
        }

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

        $domainTenant = $this->resolveFromDomain($request);

        if ($domainTenant) {
            if (! $user || $user->is_super_admin || $user->activeTenants()->whereKey($domainTenant->id)->exists()) {
                return $domainTenant;
            }
        }

        if ($user) {
            return $user->activeTenants()->active()->orderBy('tenants.id')->first();
        }

        return null;
    }

    private function resolveFromDomain(Request $request): ?Tenant
    {
        $tenant = TenantDomain::query()
            ->where('domain', strtolower($request->getHost()))
            ->with('tenant')
            ->first()?->tenant;

        return $tenant?->status === 'active' ? $tenant : null;
    }
}
