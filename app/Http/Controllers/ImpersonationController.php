<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only "see it with their eyes": the admin becomes another user of the
 * SAME tenant for this browsing session. Every start and stop is written to
 * the audit log with the true actor; nesting and admin-on-admin are refused;
 * the stop route deliberately lives OUTSIDE the admin group so it still works
 * when the impersonated role holds zero permissions.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        // Route-model binding already scopes {user} to ACTIVE members of the
        // CURRENT tenant (User's tenant_membership global scope + SoftDeletes):
        // a foreign, deactivated or deleted target 404s before reaching here.
        if ($request->session()->has('impersonator_id')) {
            throw ValidationException::withMessages([
                'impersonate' => 'Je tashmë në një simulim — dil nga ai i pari.',
            ]);
        }
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'impersonate' => 'Nuk mund ta simulosh veten.',
            ]);
        }
        if ($user->is_super_admin || $user->hasRole('admin')) {
            throw ValidationException::withMessages([
                'impersonate' => 'Adminët nuk simulohen.',
            ]);
        }

        // Recorded BEFORE the switch so the causer is the real admin.
        AuditLog::record('user.impersonation_started', $user);

        $request->session()->put('impersonator_id', $request->user()->id);
        Auth::login($user); // never "remember" an impersonated session
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull('impersonator_id');
        if (! $impersonatorId) {
            return redirect()->route('dashboard');
        }

        $target = $request->user();
        // The admin may be outside the current tenant scope in edge cases —
        // resolve without the membership scope, then fail CLOSED if gone.
        $admin = User::withoutGlobalScope('tenant_membership')->find($impersonatorId);
        if (! $admin) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        Auth::login($admin);
        $request->session()->regenerate();
        // Recorded AFTER the switch back so the causer is the real admin again.
        AuditLog::record('user.impersonation_ended', $target);

        return redirect()->route('users.index');
    }
}
