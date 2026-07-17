<?php

namespace App\Services;

use App\Models\AiAccessToken;
use App\Models\AiOAuthGrant;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AiOAuthGrantManager
{
    public function __construct(private readonly TenantContext $context) {}

    /** @param list<string> $scopes */
    public function assertCanAuthorize(User $user, string $clientId, array $scopes, Request $request): ?Tenant
    {
        if (! in_array('mcp:use', $scopes, true)) {
            return null;
        }

        $hostTenant = $this->verifiedHostTenant($request);
        $host = strtolower($request->getHost());

        return DB::transaction(function () use ($user, $clientId, $hostTenant, $host): Tenant {
            // Tenant -> user is the global lock order shared with suspension.
            // An approval can no longer commit unseen after offboarding.
            $tenant = Tenant::query()->active()->whereKey($hostTenant->id)->lockForUpdate()->first();
            abort_unless($tenant, 403, 'Hotel access is not active.');
            $this->assertRegisteredHost($tenant->id, $host);
            $lockedUser = $this->lockUser($user->id);
            $this->assertEligible($lockedUser, $tenant);
            $grant = $this->lockGrant($lockedUser->id, $clientId);

            if ($grant && ! $this->eligibleForTenant($lockedUser, $grant->tenant_id)) {
                $this->revokeClientTokens($lockedUser->id, $clientId);
                $grant->delete();
                $grant = null;
            }

            if ($grant && (int) $grant->tenant_id !== (int) $tenant->id) {
                throw new ConflictHttpException(
                    'This OAuth client is already connected to another hotel. Disconnect it there before authorizing this hotel.'
                );
            }

            return $tenant;
        });
    }

    /** @param list<string> $scopes */
    public function approve(User $user, string $clientId, array $scopes, Request $request): ?AiOAuthGrant
    {
        if (! in_array('mcp:use', $scopes, true)) {
            return null;
        }

        $hostTenant = $this->verifiedHostTenant($request);
        $host = strtolower($request->getHost());

        return DB::transaction(function () use ($user, $clientId, $hostTenant, $host): AiOAuthGrant {
            $tenant = Tenant::query()->active()->whereKey($hostTenant->id)->lockForUpdate()->first();
            abort_unless($tenant, 403, 'Hotel access is not active.');
            $this->assertRegisteredHost($tenant->id, $host);
            $lockedUser = $this->lockUser($user->id);
            $this->assertEligible($lockedUser, $tenant);
            $grant = $this->lockGrant($lockedUser->id, $clientId);

            if ($grant && ! $this->eligibleForTenant($lockedUser, $grant->tenant_id)) {
                $this->revokeClientTokens($lockedUser->id, $clientId);
                $grant->delete();
                $grant = null;
            }

            if ($grant && (int) $grant->tenant_id !== (int) $tenant->id) {
                throw new ConflictHttpException(
                    'This OAuth client is already connected to another hotel. Disconnect it there before authorizing this hotel.'
                );
            }

            return $grant ?? AiOAuthGrant::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $lockedUser->id,
                'client_id' => $clientId,
            ]);
        });
    }

    public function bindAccessToken(string $tokenId, int|string $userId, string $clientId): void
    {
        DB::transaction(function () use ($tokenId, $userId, $clientId): void {
            $token = Passport::token()->newQuery()->find($tokenId);

            if (! $token
                || $token->revoked
                || (string) $token->user_id !== (string) $userId
                || (string) $token->client_id !== $clientId
                || $token->cant('mcp:use')) {
                return;
            }

            $user = $this->findLockedUser((int) $userId);

            if (! $user) {
                $token->forceFill(['revoked' => true])->save();

                return;
            }

            $grant = $this->lockGrant($user->id, $clientId);

            if (! $grant) {
                $token->forceFill(['revoked' => true])->save();

                return;
            }

            if (! $this->eligibleForTenant($user, $grant->tenant_id)) {
                $this->revokeClientTokens($user->id, $clientId);
                $grant->delete();

                return;
            }

            AiAccessToken::query()->updateOrCreate(
                ['access_token_id' => $tokenId],
                ['tenant_id' => $grant->tenant_id, 'user_id' => $user->id, 'client_id' => $clientId],
            );
        });
    }

    public function validateRefreshToken(string $refreshTokenId, string $accessTokenId): void
    {
        DB::transaction(function () use ($refreshTokenId, $accessTokenId): void {
            $token = Passport::token()->newQuery()->find($accessTokenId);

            if (! $token || $token->cant('mcp:use')) {
                return;
            }

            $user = $token->user_id ? $this->findLockedUser((int) $token->user_id) : null;
            $grant = $user ? $this->lockGrant($user->id, (string) $token->client_id) : null;
            $binding = AiAccessToken::query()->find($accessTokenId);
            $valid = $user
                && $grant
                && $binding
                && ! $token->revoked
                && (int) $binding->user_id === (int) $token->user_id
                && hash_equals((string) $binding->client_id, (string) $token->client_id)
                && (int) $binding->tenant_id === (int) $grant->tenant_id
                && $this->eligibleForTenant($user, $grant->tenant_id);

            if (! $valid) {
                DB::table('oauth_refresh_tokens')->where('id', $refreshTokenId)->update(['revoked' => true]);
                Passport::token()->newQuery()->whereKey($accessTokenId)->update(['revoked' => true]);
            }
        });
    }

    public function disconnectTenant(int $userId, int $tenantId): void
    {
        DB::transaction(function () use ($userId, $tenantId): void {
            $this->lockUser($userId);
            $grants = AiOAuthGrant::query()
                ->where('user_id', $userId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->get();

            foreach ($grants as $grant) {
                $this->revokeClientTokens($userId, $grant->client_id);
                $grant->delete();
            }

            $orphanIds = AiAccessToken::query()
                ->where('user_id', $userId)
                ->where('tenant_id', $tenantId)
                ->pluck('access_token_id');

            if ($orphanIds->isNotEmpty()) {
                DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $orphanIds)->update(['revoked' => true]);
                DB::table('oauth_access_tokens')->whereIn('id', $orphanIds)->update(['revoked' => true]);
                AiAccessToken::query()->whereIn('access_token_id', $orphanIds)->delete();
            }
        });
    }

    public function revokeGrant(int $userId, string $clientId): void
    {
        DB::transaction(function () use ($userId, $clientId): void {
            $this->lockUser($userId);
            $grant = $this->lockGrant($userId, $clientId);
            $this->revokeClientTokens($userId, $clientId);
            $grant?->delete();
        });
    }

    public function disconnectUser(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $user = $this->findLockedUser($userId);

            if (! $user) {
                return;
            }

            $grants = AiOAuthGrant::query()->where('user_id', $userId)->lockForUpdate()->get();

            foreach ($grants as $grant) {
                $this->revokeClientTokens($userId, $grant->client_id);
                $grant->delete();
            }

            $orphanIds = AiAccessToken::query()->where('user_id', $userId)->pluck('access_token_id');

            if ($orphanIds->isNotEmpty()) {
                DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $orphanIds)->update(['revoked' => true]);
                DB::table('oauth_access_tokens')->whereIn('id', $orphanIds)->update(['revoked' => true]);
                AiAccessToken::query()->whereIn('access_token_id', $orphanIds)->delete();
            }
        });
    }

    public function disconnectAllForTenant(int $tenantId): void
    {
        $userIds = AiOAuthGrant::query()
            ->where('tenant_id', $tenantId)
            ->pluck('user_id')
            ->merge(AiAccessToken::query()->where('tenant_id', $tenantId)->pluck('user_id'))
            ->unique()
            ->sort()
            ->values();

        foreach ($userIds as $userId) {
            $this->disconnectTenant((int) $userId, $tenantId);
        }
    }

    private function verifiedHostTenant(Request $request): Tenant
    {
        $tenant = $this->context->tenant();
        $verified = $tenant
            && $tenant->status === 'active'
            && TenantDomain::query()
                ->where('tenant_id', $tenant->id)
                ->where('domain', strtolower($request->getHost()))
                ->exists();

        abort_unless($verified, 404, 'OAuth authorization must use the registered hotel domain.');

        return $tenant;
    }

    private function assertRegisteredHost(int $tenantId, string $host): void
    {
        $domain = TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->where('domain', $host)
            ->lockForUpdate()
            ->first();

        abort_unless($domain, 404, 'OAuth authorization must use the registered hotel domain.');
    }

    private function lockUser(int $userId): User
    {
        return $this->findLockedUser($userId) ?? throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
    }

    private function findLockedUser(int $userId): ?User
    {
        return User::withoutGlobalScopes()->whereKey($userId)->lockForUpdate()->first();
    }

    private function lockGrant(int $userId, string $clientId): ?AiOAuthGrant
    {
        return AiOAuthGrant::query()
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->lockForUpdate()
            ->first();
    }

    private function assertEligible(User $user, Tenant $tenant): void
    {
        abort_unless($this->eligibleForTenant($user, $tenant->id), 403, 'Hotel access is not active.');
    }

    private function eligibleForTenant(User $user, int $tenantId): bool
    {
        return ! $user->trashed()
            && Tenant::query()->active()->whereKey($tenantId)->exists()
            && ($user->is_super_admin || DB::table('tenant_user')
                ->where('user_id', $user->id)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->exists());
    }

    private function revokeClientTokens(int $userId, string $clientId): void
    {
        DB::table('oauth_auth_codes')
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->update(['revoked' => true]);

        $ids = DB::table('oauth_access_tokens')
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->update(['revoked' => true]);
            DB::table('oauth_access_tokens')->whereIn('id', $ids)->update(['revoked' => true]);
            AiAccessToken::query()->whereIn('access_token_id', $ids)->delete();
        }
    }
}
