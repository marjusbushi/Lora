<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Channex + POK post webhooks server-to-server (no CSRF token). Channex uses a
        // shared-secret header; POK re-verifies every event via getOrder (never trusts the body).
        $middleware->validateCsrfTokens(except: ['channex/webhook', 'pok/webhook']);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Retention: channel sync audit (90d) + website search demand log (2y).
        $schedule->command('model:prune', ['--model' => [\App\Models\ChannelSyncLog::class, \App\Models\WebsiteSearchLog::class]])->daily();
        // Catch-up: re-pull any OTA booking a missed webhook left unacknowledged.
        $schedule->command('channex:pull-bookings')->everyFifteenMinutes()->withoutOverlapping();
        // On-the-books snapshot per future date × room type (pickup-pace history).
        // Runs before the 04:00 ARI push so both see the same overnight state.
        $schedule->command('pricing:snapshot')->dailyAt('03:30');
        // Nightly safety-net: re-push availability + rates in case a real-time push was missed.
        $schedule->command('channex:push-ari --queue')->dailyAt('04:00');
        // Free abandoned holds: cancel pending direct bookings whose POK payment never completed.
        $schedule->command('pok:release-unpaid')->everyFiveMinutes()->withoutOverlapping();
        // Guarded auto-pricing (owner-enabled only), between snapshot and ARI push.
        $schedule->command('pricing:autopilot')->dailyAt('03:45');
        // Monday-morning pricing narrative for the owner (skips if Gemini unset).
        $schedule->command('pricing:weekly-report')->weeklyOn(1, '07:00');
    })
    ->create();
