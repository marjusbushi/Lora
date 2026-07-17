<?php

use App\Console\TenantCommandRunner;
use App\Http\Middleware\EnsureControlPanelHost;
use App\Http\Middleware\EnsureHotelHost;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantModuleEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectDedicatedControlPanel;
use App\Http\Middleware\ResolveTenant;
use App\Models\ChannelSyncLog;
use App\Models\WebsiteSearchLog;
use App\Services\TenantBillingService;
use App\Support\TrustedHostPatterns;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Exact platform + registered tenant hosts only. Do not trust arbitrary
        // subdomains of APP_URL; every hotel domain must be explicitly registered.
        $middleware->trustHosts(
            at: static fn (): array => TrustedHostPatterns::all(),
            subdomains: false,
        );

        $middleware->web(append: [
            ResolveTenant::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Tenant resolution needs the session/auth user, but must happen before
        // implicit route-model binding so a foreign tenant ID can never bind.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        // Channex + POK post webhooks server-to-server (no CSRF token). Channex uses a
        // shared-secret header; POK re-verifies every event via getOrder (never trusts the body).
        $middleware->validateCsrfTokens(except: ['channex/webhook', 'pok/webhook']);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'super_admin' => EnsureSuperAdmin::class,
            'control_panel_host' => EnsureControlPanelHost::class,
            'dedicated_control_redirect' => RedirectDedicatedControlPanel::class,
            'hotel_host' => EnsureHotelHost::class,
            'module' => EnsureTenantModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Retention: channel sync audit (90d) + website search demand log (2y).
        $schedule->call(fn () => app(TenantCommandRunner::class)->run(
            'model:prune',
            ['--model' => [ChannelSyncLog::class, WebsiteSearchLog::class]],
        ))->name('tenants:model:prune')->daily();
        // Catch-up: re-pull any OTA booking a missed webhook left unacknowledged.
        $schedule->call(fn () => app(TenantCommandRunner::class)->run(
            'channex:pull-bookings',
            requiresChannex: true,
            requiredModule: TenantBillingService::CHANNEL_MANAGER,
        ))->name('tenants:channex:pull-bookings')->everyFifteenMinutes()->withoutOverlapping();
        // On-the-books snapshot per future date × room type (pickup-pace history).
        // Runs before the 04:00 ARI push so both see the same overnight state.
        $schedule->call(fn () => app(TenantCommandRunner::class)->run('pricing:snapshot'))
            ->name('tenants:pricing:snapshot')->dailyAt('03:30');
        // Nightly safety-net: re-push availability + rates in case a real-time push was missed.
        $schedule->call(fn () => app(TenantCommandRunner::class)->run(
            'channex:push-ari',
            ['--queue' => true, '--reconcile-fixed' => true],
            requiresChannex: true,
            requiredModule: TenantBillingService::CHANNEL_MANAGER,
        ))->name('tenants:channex:push-ari')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
        // Free abandoned holds: cancel pending direct bookings whose POK payment never completed.
        $schedule->call(fn () => app(TenantCommandRunner::class)->run(
            'pok:release-unpaid',
            requiredModule: TenantBillingService::BOOKING_ENGINE,
        ))
            ->name('tenants:pok:release-unpaid')->everyFiveMinutes()->withoutOverlapping();
        // Guarded auto-pricing (owner-enabled only), between snapshot and ARI push.
        $schedule->call(fn () => app(TenantCommandRunner::class)->run(
            'pricing:autopilot',
            requiredModule: TenantBillingService::SMART_PRICING,
        ))
            ->name('tenants:pricing:autopilot')->dailyAt('03:45')->withoutOverlapping()->onOneServer();
        // Monday-morning pricing narrative for the owner (skips if Gemini unset).
        $schedule->call(fn () => app(TenantCommandRunner::class)->run(
            'pricing:weekly-report',
            requiredModule: TenantBillingService::SMART_PRICING,
        ))
            ->name('tenants:pricing:weekly-report')->weeklyOn(1, '07:00');
        // Competitor-price snapshot (rate shopping) — a no-op unless the owner
        // enabled it in Settings; the command itself honours the frequency.
        $schedule->call(fn () => app(TenantCommandRunner::class)->run('market:fetch-rates', ['--scheduled' => true]))
            ->name('tenants:market:fetch-rates')->dailyAt('05:30')->withoutOverlapping()->onOneServer();
        // Daily exchange rates (Settings → Monedhat) — no-op unless enabled.
        $schedule->call(fn () => app(TenantCommandRunner::class)->run('currency:fetch-rates'))
            ->name('tenants:currency:fetch-rates')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
        // Midnight: archive inspected cleaning tasks so the board shows only the day's live work.
        $schedule->call(fn () => app(TenantCommandRunner::class)->run(
            'housekeeping:archive-inspected',
            requiredModule: TenantBillingService::HOUSEKEEPING,
        ))
            ->name('tenants:housekeeping:archive-inspected')->daily();
        // Platform billing is driven by each active subscription's next_billing_at.
        $schedule->command('billing:run-recurring')
            ->name('platform:billing:run-recurring')
            ->dailyAt('00:10')
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->create();
