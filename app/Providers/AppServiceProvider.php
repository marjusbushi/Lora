<?php

namespace App\Providers;

use App\Http\Controllers\OAuth\ApproveAuthorizationController;
use App\Http\Controllers\OAuth\AuthorizationController;
use App\Models\User;
use App\Services\AiOAuthGrantManager;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as PassportApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->bind(PassportAuthorizationController::class, AuthorizationController::class);
        $this->app->bind(PassportApproveAuthorizationController::class, ApproveAuthorizationController::class);
        $this->app->when(AuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(fn () => Auth::guard(config('passport.guard', null)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(MigrationsStarted::class, fn () => app(TenantContext::class)->beginSchemaBootstrap());
        Event::listen(MigrationsEnded::class, fn () => app(TenantContext::class)->endSchemaBootstrap());
        User::deleting(fn (User $user) => app(AiOAuthGrantManager::class)->disconnectUser($user->id));

        Passport::tokensCan(array_merge(Passport::$scopes ?? [], [
            'mcp:use' => 'Use MCP server',
            'offline_access' => 'Maintain the connection when the current access token expires',
        ]));

        Passport::authorizationView(function (array $parameters) {
            $scopeIds = collect($parameters['scopes'])
                ->map(fn ($scope): string => $scope->id)
                ->values()
                ->all();
            try {
                $oauthTenant = app(AiOAuthGrantManager::class)->assertCanAuthorize(
                    $parameters['user'],
                    (string) $parameters['client']->id,
                    $scopeIds,
                    app(Request::class),
                );
            } catch (ConflictHttpException) {
                $request = $parameters['request'];
                $redirectUri = (string) $request->query('redirect_uri');
                $query = http_build_query(array_filter([
                    'error' => 'access_denied',
                    'error_description' => 'This OAuth client is already connected to another hotel. Disconnect it there first.',
                    'state' => $request->query('state'),
                ], static fn ($value) => $value !== null));

                return redirect()->away($redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query);
            }

            return view('mcp.authorize', [...$parameters, 'oauthTenant' => $oauthTenant]);
        });

        RateLimiter::for('mcp', fn ($request) => Limit::perMinute(90)->by(
            ($request->user('api')?->id ?? 'guest').'|'.$request->ip()
        ));

        RateLimiter::for('mcp-oauth-public', function ($request) {
            if ($request->is('oauth/register')) {
                return [
                    Limit::perMinute(10)->by('mcp-register-minute|'.$request->ip()),
                    Limit::perDay(100)->by('mcp-register-day|'.$request->ip()),
                ];
            }

            return Limit::perMinute(120)->by('mcp-metadata|'.$request->ip());
        });

        // Channex calls every hotel's webhook from the same IPs — key the
        // budget on the tenant's host so hotels never starve each other.
        RateLimiter::for('channex-webhook', function ($request) {
            return Limit::perMinute(120)->by(strtolower($request->getHost()).'|'.$request->ip());
        });

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);
    }
}
