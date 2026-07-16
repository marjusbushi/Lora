<?php

namespace App\Http\Middleware;

use App\Models\AiAccessToken;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveMcpTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');
        $tokenId = $user?->currentAccessToken()?->getKey();
        $binding = $tokenId ? AiAccessToken::query()->find($tokenId) : null;

        abort_unless($user && $binding && (int) $binding->user_id === (int) $user->id, 403, 'AI connection is not bound to a hotel.');

        $tenant = Tenant::query()->active()->find($binding->tenant_id);
        $allowed = $tenant && ($user->is_super_admin || $user->activeTenants()->whereKey($tenant->id)->exists());
        abort_unless($allowed, 403, 'Hotel access is no longer active.');

        $this->context->set($tenant);
        $previousGuard = Auth::getDefaultDriver();
        Auth::shouldUse('api');

        try {
            return $next($request);
        } finally {
            Auth::shouldUse($previousGuard);
            $this->context->clear();
        }
    }
}
