<?php

namespace App\Listeners;

use App\Models\AiAccessToken;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Passport\Events\AccessTokenCreated;

class BindAiAccessTokenToTenant
{
    public function handle(AccessTokenCreated $event): void
    {
        if (! $event->userId) {
            return;
        }

        $user = User::withoutGlobalScopes()->find($event->userId);
        $tenant = $user?->current_tenant_id
            ? Tenant::query()->active()->find($user->current_tenant_id)
            : null;

        if (! $user || ! $tenant) {
            return;
        }

        $allowed = $user->is_super_admin
            || $user->activeTenants()->whereKey($tenant->id)->exists();

        if (! $allowed) {
            return;
        }

        AiAccessToken::updateOrCreate(
            ['access_token_id' => $event->tokenId],
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_id' => $event->clientId],
        );
    }
}
