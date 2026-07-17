<?php

namespace App\Http\Middleware;

use App\Models\AiAccessToken;
use App\Models\AiOAuthGrant;
use App\Models\Tenant;
use App\Services\AiOAuthGrantManager;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveMcpTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AiOAuthGrantManager $grants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');
        $accessToken = $user?->currentAccessToken();
        $tokenId = $accessToken?->getKey();
        $binding = $tokenId ? AiAccessToken::query()->find($tokenId) : null;

        abort_unless($user && $binding && (int) $binding->user_id === (int) $user->id, 403, 'AI connection is not bound to a hotel.');

        $clientId = (string) $accessToken->client_id;
        $validGrant = $clientId !== ''
            && hash_equals($clientId, (string) $binding->client_id)
            && AiOAuthGrant::query()
                ->where('user_id', $user->id)
                ->where('client_id', $clientId)
                ->where('tenant_id', $binding->tenant_id)
                ->exists();

        if (! $validGrant) {
            if ($clientId !== '') {
                $this->grants->revokeGrant($user->id, $clientId);
            }

            if ($binding->client_id && (string) $binding->client_id !== $clientId) {
                $this->grants->revokeGrant($user->id, (string) $binding->client_id);
            }

            abort(403, 'AI connection grant is no longer valid.');
        }

        $tenant = Tenant::query()->active()->find($binding->tenant_id);
        $allowed = $tenant && ($user->is_super_admin || $user->activeTenants()->whereKey($tenant->id)->exists());

        if (! $allowed) {
            if ($binding->client_id) {
                $this->grants->revokeGrant($user->id, $binding->client_id);
            }

            abort(403, 'Hotel access is no longer active.');
        }

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
