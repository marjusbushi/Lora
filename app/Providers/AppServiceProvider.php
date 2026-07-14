<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(MigrationsStarted::class, fn () => app(TenantContext::class)->beginSchemaBootstrap());
        Event::listen(MigrationsEnded::class, fn () => app(TenantContext::class)->endSchemaBootstrap());

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
