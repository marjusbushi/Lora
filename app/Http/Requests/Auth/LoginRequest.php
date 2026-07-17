<?php

namespace App\Http\Requests\Auth;

use App\Models\TenantDomain;
use App\Models\TenantUserInvitation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $authenticated = Auth::attempt($this->only('email', 'password'), $this->boolean('remember'));

        if (! $authenticated) {
            $invitedUser = $this->invitedUserForIntendedUrl();

            if ($invitedUser && Hash::check((string) $this->input('password'), $invitedUser->password)) {
                // A globally soft-deleted account is restored only after its
                // owner proves both mailbox-link possession and credentials.
                // Tenant membership is still deferred to the accept POST.
                if ($invitedUser->trashed()) {
                    $invitedUser->restore();
                }

                if (Hash::needsRehash($invitedUser->password)) {
                    $invitedUser->forceFill(['password' => Hash::make((string) $this->input('password'))])->save();
                }

                Auth::guard('web')->login($invitedUser, $this->boolean('remember'));
                $authenticated = true;
            }
        }

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * A user who is not a tenant member yet may authenticate only when this
     * request is the continuation of that tenant's valid invitation link.
     */
    private function invitedUserForIntendedUrl(): ?User
    {
        $intended = $this->session()->get('url.intended');

        if (! is_string($intended) || $intended === '' || strlen($intended) > 2048) {
            return null;
        }

        $intendedHost = parse_url($intended, PHP_URL_HOST);
        if (! is_string($intendedHost)
            || ! hash_equals(strtolower($this->getHost()), strtolower($intendedHost))) {
            return null;
        }

        try {
            $intendedRequest = HttpRequest::create($intended, 'GET');
            if (! $intendedRequest->hasValidRelativeSignature()) {
                return null;
            }

            $route = app('router')->getRoutes()->match($intendedRequest);
            if ($route->getName() !== 'tenant-invitations.show') {
                return null;
            }

            $invitationId = $route->parameter('invitation');
            if (! is_string($invitationId)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $invitation = TenantUserInvitation::query()
            ->whereKey($invitationId)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $invitation
            || app(TenantContext::class)->id() !== (int) $invitation->tenant_id
            || ! TenantDomain::query()
                ->where('tenant_id', $invitation->tenant_id)
                ->where('domain', strtolower($this->getHost()))
                ->exists()) {
            return null;
        }

        $email = strtolower(trim((string) $this->input('email')));
        if (! hash_equals($invitation->email, $email)) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->whereKey($invitation->user_id)
            ->whereRaw('LOWER(email) = ?', [$invitation->email])
            ->first();
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
