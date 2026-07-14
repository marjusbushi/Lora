<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        if ($this->isDedicatedControlPanelHost($request) && ! $request->user()->is_super_admin) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'Kjo hyrje është vetëm për administratorët e Lora PMS.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended($this->redirectForRole($request->user()));
    }

    protected function redirectForRole($user): string
    {
        if ($user->is_super_admin && $this->isProductHost(request())) {
            return rtrim((string) config('lora.control_panel_url'), '/').'/super-admin';
        }

        return match (true) {
            $user->hasRole('housekeeping') => '/housekeeping',
            $user->hasRole('pos_staff') => '/pos',
            default => '/dashboard',
        };
    }

    private function isProductHost(Request $request): bool
    {
        $host = strtolower($request->getHost());

        return in_array($host, config('lora.control_panel_hosts', []), true)
            || in_array($host, config('lora.marketing_hosts', []), true);
    }

    private function isDedicatedControlPanelHost(Request $request): bool
    {
        return in_array(
            strtolower($request->getHost()),
            config('lora.dedicated_control_panel_hosts', []),
            true,
        );
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
