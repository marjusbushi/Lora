<?php

namespace App\Http\Middleware;

use App\Models\TenantUserInvitation;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Exceptions\InvalidSignatureException;

class AuthenticateSignedTenantInvitation extends Authenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        if (! $request->hasValidRelativeSignature()) {
            throw new InvalidSignatureException;
        }

        // Signature validity alone is insufficient after a reissue: the old
        // signature is still cryptographically valid until its expiry. Require
        // the exact invitation grant to remain current before redirecting to
        // login or allowing route-model binding.
        $invitationId = $request->route('invitation');
        abort_unless(
            is_string($invitationId)
                && TenantUserInvitation::query()->whereKey($invitationId)->exists(),
            403,
        );

        return parent::handle($request, $next, ...$guards);
    }
}
