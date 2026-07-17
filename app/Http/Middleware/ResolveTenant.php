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
            // Honor legacy rows that stored an exact www host. New domain
            // writes are canonicalized without www, but existing hotels must
            // remain reachable while those rows are cleaned up.
            $exactRegistration = TenantDomain::query()
                ->where('domain', strtolower($request->getHost()))
                ->exists();

            if ($exactRegistration) {
                return $this->resolveRequest($request, $next);
            }

            $canonicalHost = substr($request->getHost(), 4);
            $knownPlatformHost = in_array(strtolower($request->getHost()), array_merge(
                config('lora.marketing_hosts', []),
                config('lora.control_panel_hosts', []),
                config('lora.dedicated_control_panel_hosts', []),
            ), true);
            $knownTenantHost = TenantDomain::query()->where('domain', strtolower($canonicalHost))->exists();

            abort_unless($knownPlatformHost || $knownTenantHost, 404, 'Hotel not found.');

            $canonicalUrl = $request->getScheme().'://'.$canonicalHost.$request->getRequestUri();

            return redirect()->away($canonicalUrl, Response::HTTP_PERMANENTLY_REDIRECT);
        }

        return $this->resolveRequest($request, $next);
    }

    private function resolveRequest(Request $request, Closure $next): Response
    {
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
        if ($request->routeIs('tenant-invitations.*')) {
            // The recipient is not a member until the signed invitation POST
            // succeeds. Hydrate the session user while tenant context is still
            // empty; the invitation controller then enforces recipient, host,
            // expiry and signature before it can grant any membership.
            $request->user();
        }

        // Public surfaces — the guest website, booking engine, and external
        // webhooks — always belong to the host that was called. A visitor's
        // login (or a super admin's tenant switch) must never move a public
        // page, a booking, or a webhook onto another hotel.
        if ($request->routeIs(
            'website.*',
            'channex.webhook',
            'pwa.manifest',
            'tenant-handoff.consume',
            'tenant-invitations.*',
        )) {
            return $this->resolveFromDomain($request);
        }

        $user = $request->user();

        // A registered hotel domain is authoritative for back-office requests
        // too. Session/current tenant are only fallbacks for hosts that are not
        // registered to a hotel (for example legacy internal flows). Never let
        // a stale Hotel A session render data on Hotel B's domain.
        $domain = $this->domainRegistration($request);

        if ($domain) {
            $tenant = $domain->tenant;

            if (! $tenant || $tenant->status !== 'active') {
                return null;
            }

            if (! $user
                || $user->is_super_admin
                || $user->activeTenants()->whereKey($tenant->id)->exists()) {
                return $tenant;
            }

            // The host belongs to a real hotel, but this user does not. Do not
            // fall back to one of their other memberships on the wrong domain.
            return null;
        }

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

        if ($user) {
            return $user->activeTenants()->active()->orderBy('tenants.id')->first();
        }

        return null;
    }

    private function resolveFromDomain(Request $request): ?Tenant
    {
        $tenant = $this->domainRegistration($request)?->tenant;

        return $tenant?->status === 'active' ? $tenant : null;
    }

    private function domainRegistration(Request $request): ?TenantDomain
    {
        return TenantDomain::query()
            ->where('domain', strtolower($request->getHost()))
            ->with('tenant')
            ->first();
    }
}
