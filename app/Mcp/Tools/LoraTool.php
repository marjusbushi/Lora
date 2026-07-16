<?php

namespace App\Mcp\Tools;

use App\Models\Setting;
use App\Models\User;
use App\Services\TenantBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

abstract class LoraTool extends Tool
{
    protected function user(Request $request, ?string $permission = null): User
    {
        $user = $request->user('api');

        if (! $user instanceof User || ($permission && ! $user->can($permission))) {
            throw new AuthorizationException('You are not allowed to use this hotel tool.');
        }

        return $user;
    }

    protected function enabled(string $key, bool $default = true): bool
    {
        return filter_var(Setting::get('ai_mcp.'.$key, $default), FILTER_VALIDATE_BOOL);
    }

    protected function moduleEnabled(string $module): bool
    {
        return app(TenantBillingService::class)->enabled($module, app(TenantContext::class)->tenant());
    }
}
