<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ChannexController;
use App\Http\Controllers\ChannexWebhookController;
use App\Http\Controllers\CleaningTaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\GuestMergeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LoraAiController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MessagesController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosShiftController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationFiscalizationController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SeasonCopyController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SmartPricingController;
use App\Http\Controllers\SuperAdmin\BillingInvoiceController as SuperAdminBillingInvoiceController;
use App\Http\Controllers\SuperAdmin\BillingPaymentAttemptController as SuperAdminBillingPaymentAttemptController;
use App\Http\Controllers\SuperAdmin\BillingPaymentController as SuperAdminBillingPaymentController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\FatureAlOnboardingController as SuperAdminFatureAlOnboardingController;
use App\Http\Controllers\SuperAdmin\OnboardingController as SuperAdminOnboardingController;
use App\Http\Controllers\SuperAdmin\ProfileController as SuperAdminProfileController;
use App\Http\Controllers\SuperAdmin\ProviderEventController as SuperAdminProviderEventController;
use App\Http\Controllers\SuperAdmin\TenantController as SuperAdminTenantController;
use App\Http\Controllers\TenantHandoffController;
use App\Http\Controllers\TenantUserInvitationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebsiteController;
use App\Http\Middleware\AuthenticateSignedTenantInvitation;
use App\Models\Setting;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ===== PUBLIC WEBSITE =====
// Root is host-aware: Lora product hosts get the marketing site, the admin
// subdomain goes to the back-office, and hotel domains keep their booking site.
Route::get('/', function (Request $request) {
    if (in_array(strtolower($request->getHost()), config('lora.marketing_hosts', []), true)) {
        return Inertia::render('Marketing/Home');
    }

    if (in_array(strtolower($request->getHost()), config('lora.dedicated_control_panel_hosts', []), true)) {
        return redirect()->route($request->user()?->is_super_admin ? 'super-admin.dashboard' : 'login');
    }

    if (str_starts_with($request->getHost(), 'admin.')) {
        return redirect()->route('dashboard');
    }

    return app(WebsiteController::class)->home();
})->name('website.home');
Route::get('/rooms', [WebsiteController::class, 'rooms'])->name('website.rooms');
Route::get('/book', [WebsiteController::class, 'bookingForm'])->middleware('module:booking_engine')->name('website.book');
Route::post('/book/check', [WebsiteController::class, 'checkAvailability'])->middleware(['module:booking_engine', 'throttle:30,1'])->name('website.book.check');
Route::get('/book/availability', [WebsiteController::class, 'availability'])->middleware(['module:booking_engine', 'throttle:60,1'])->name('website.book.availability');
Route::post('/book', [WebsiteController::class, 'submitBooking'])->middleware(['module:booking_engine', 'throttle:10,1'])->name('website.book.submit');
Route::get('/book/confirmation/{token}', [WebsiteController::class, 'bookingConfirmation'])->middleware('module:booking_engine')->name('website.booking.confirmation');

// POK card payment (embedded) for a pending website booking.
Route::get('/book/pay/{token}', [WebsiteController::class, 'bookingPayment'])->middleware('module:booking_engine')->name('website.pay.show');
Route::post('/book/pay/{token}', [WebsiteController::class, 'confirmPayment'])->middleware(['module:booking_engine', 'throttle:20,1'])->name('website.pay.confirm');
// POK server-to-server webhook (CSRF-excluded in bootstrap/app.php; verifies via getOrder, never trusts the body).
Route::post('/pok/webhook', [WebsiteController::class, 'paymentWebhook'])->middleware(['module:booking_engine', 'throttle:120,1'])->name('website.pay.webhook');

Route::get('/about', [WebsiteController::class, 'about'])->name('website.about');
Route::get('/contact', [WebsiteController::class, 'contact'])->name('website.contact');
Route::post('/contact', [WebsiteController::class, 'submitContact'])->middleware('throttle:5,1')->name('website.contact.submit');

// Inbound Channex booking webhook (server-to-server; CSRF-excluded in bootstrap/app.php).
// Auth is a shared secret header validated in the controller — Channex has no HMAC.
Route::post('/channex/webhook', [ChannexWebhookController::class, 'handle'])->middleware(['module:channel_manager', 'throttle:channex-webhook'])->name('channex.webhook');

// Short-lived, one-time Control Panel -> hotel-domain authentication callback.
Route::get('/tenant-handoff', TenantHandoffController::class)
    ->middleware(['hotel_host', 'throttle:10,1'])
    ->name('tenant-handoff.consume');

// Existing global accounts must explicitly accept before tenant membership or
// roles are granted. Both the review page and the state-changing POST are
// short-lived signed URLs on the invited hotel's own domain.
Route::middleware([
    AuthenticateSignedTenantInvitation::class,
    'hotel_host',
    'throttle:10,1',
])
    ->prefix('tenant-invitations')
    ->name('tenant-invitations.')
    ->group(function () {
        Route::get('/{invitation}', [TenantUserInvitationController::class, 'show'])->name('show');
        Route::post('/{invitation}/accept', [TenantUserInvitationController::class, 'accept'])->name('accept');
    });

// PWA manifest — dynamic so the installed app carries the hotel's own name
// (same tenant branding the <title> uses). display:standalone is what removes
// the browser URL bar when the site is added to a phone's home screen.
Route::get('/manifest.webmanifest', function () {
    $tenant = app(TenantContext::class)->tenant();
    $name = (string) (Setting::get('hotel.name') ?: $tenant?->name ?: 'Hotel');

    return response()->json([
        'name' => $name,
        'short_name' => $name,
        'start_url' => '/dashboard',
        'scope' => '/',
        'display' => 'standalone',
        'background_color' => '#fafaf9',
        'theme_color' => '#2d6a4f',
        'icons' => [
            ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
    ], 200, ['Content-Type' => 'application/manifest+json'])
        ->setCache(['private' => true, 'max_age' => 3600]);
})->middleware('hotel_host')->name('pwa.manifest');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'hotel_host', 'dedicated_control_redirect'])->name('dashboard');

Route::middleware(['auth', 'verified', 'super_admin', 'control_panel_host'])
    ->prefix('super-admin')
    ->name('super-admin.')
    ->group(function () {
        Route::get('/', [SuperAdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/activity', [SuperAdminDashboardController::class, 'activity'])->name('activity');
        Route::get('/onboarding', [SuperAdminOnboardingController::class, 'index'])->name('onboarding.index');
        Route::get('/onboarding/{tenant}', [SuperAdminOnboardingController::class, 'show'])->name('onboarding.show');
        Route::get('/onboarding/{tenant}/fiscalization', [SuperAdminFatureAlOnboardingController::class, 'show'])->name('onboarding.fiscalization.show');
        Route::post('/onboarding/{tenant}/fiscalization/register', [SuperAdminFatureAlOnboardingController::class, 'register'])->middleware('throttle:30,1')->name('onboarding.fiscalization.register');
        Route::post('/onboarding/{tenant}/fiscalization/certificate', [SuperAdminFatureAlOnboardingController::class, 'certificate'])->middleware('throttle:30,1')->name('onboarding.fiscalization.certificate');
        Route::post('/onboarding/{tenant}/fiscalization/branch', [SuperAdminFatureAlOnboardingController::class, 'branch'])->middleware('throttle:30,1')->name('onboarding.fiscalization.branch');
        Route::post('/onboarding/{tenant}/fiscalization/device', [SuperAdminFatureAlOnboardingController::class, 'device'])->middleware('throttle:30,1')->name('onboarding.fiscalization.device');
        Route::post('/onboarding/{tenant}/fiscalization/user', [SuperAdminFatureAlOnboardingController::class, 'user'])->middleware('throttle:30,1')->name('onboarding.fiscalization.user');
        Route::post('/onboarding/{tenant}/fiscalization/bank-account', [SuperAdminFatureAlOnboardingController::class, 'bankAccount'])->middleware('throttle:30,1')->name('onboarding.fiscalization.bank-account');
        Route::post('/onboarding/{tenant}/fiscalization/verify', [SuperAdminFatureAlOnboardingController::class, 'verify'])->middleware('throttle:10,1')->name('onboarding.fiscalization.verify');
        Route::patch('/onboarding/{tenant}', [SuperAdminOnboardingController::class, 'update'])->name('onboarding.update');
        Route::patch('/onboarding/{tenant}/steps/{step}', [SuperAdminOnboardingController::class, 'updateStep'])->name('onboarding.steps.update');
        Route::patch('/onboarding/{tenant}/steps/{step}/tasks/{task}', [SuperAdminOnboardingController::class, 'updateTask'])->name('onboarding.tasks.update');
        Route::post('/onboarding/{tenant}/documents', [SuperAdminOnboardingController::class, 'storeDocument'])->name('onboarding.documents.store');
        Route::get('/onboarding/{tenant}/documents/{document}', [SuperAdminOnboardingController::class, 'downloadDocument'])->name('onboarding.documents.download');
        Route::delete('/onboarding/{tenant}/documents/{document}', [SuperAdminOnboardingController::class, 'destroyDocument'])->name('onboarding.documents.destroy');
        Route::post('/onboarding/{tenant}/activate', [SuperAdminOnboardingController::class, 'activate'])->name('onboarding.activate');
        Route::get('/profile', [SuperAdminProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [SuperAdminProfileController::class, 'update'])->name('profile.update');
        Route::get('/tenants', [SuperAdminTenantController::class, 'index'])->name('tenants.index');
        Route::get('/tenants/{tenant}', [SuperAdminTenantController::class, 'show'])->name('tenants.show');
        Route::post('/tenants', [SuperAdminTenantController::class, 'store'])->name('tenants.store');
        Route::patch('/tenants/{tenant}', [SuperAdminTenantController::class, 'update'])->name('tenants.update');
        Route::post('/tenants/{tenant}/members', [SuperAdminTenantController::class, 'storeMember'])->name('tenants.members.store');
        Route::put('/tenants/{tenant}/members/{member}', [SuperAdminTenantController::class, 'updateMember'])->name('tenants.members.update');
        Route::put('/tenants/{tenant}/subscription', [SuperAdminTenantController::class, 'updateSubscription'])->name('tenants.subscription.update');
        Route::post('/tenants/{tenant}/switch', [SuperAdminTenantController::class, 'switch'])->name('tenants.switch');
        Route::patch('/tenants/{tenant}/status', [SuperAdminTenantController::class, 'updateStatus'])->name('tenants.status');
        Route::put('/tenants/{tenant}/integrations/{provider}', [SuperAdminTenantController::class, 'updateIntegration'])
            ->whereIn('provider', ['channex', 'pok', 'fature_al'])->name('tenants.integrations.update');
        Route::post('/tenants/{tenant}/integrations/{provider}/test', [SuperAdminTenantController::class, 'testIntegration'])
            ->whereIn('provider', ['fature_al'])->middleware('throttle:10,1')->name('tenants.integrations.test');
        Route::post('/tenants/{tenant}/domains', [SuperAdminTenantController::class, 'storeDomain'])->name('tenants.domains.store');
        Route::delete('/tenants/{tenant}/domains/{domain}', [SuperAdminTenantController::class, 'destroyDomain'])
            ->scopeBindings()->name('tenants.domains.destroy');
        Route::patch('/tenants/{tenant}/domains/{domain}/primary', [SuperAdminTenantController::class, 'makePrimaryDomain'])
            ->scopeBindings()->name('tenants.domains.primary');
        Route::get('/billing/invoices', [SuperAdminBillingInvoiceController::class, 'index'])->name('billing.invoices.index');
        Route::get('/billing/invoices/{invoice}', [SuperAdminBillingInvoiceController::class, 'show'])->name('billing.invoices.show');
        Route::post('/billing/invoices', [SuperAdminBillingInvoiceController::class, 'store'])->name('billing.invoices.store');
        Route::patch('/billing/invoices/{invoice}/publish', [SuperAdminBillingInvoiceController::class, 'publish'])->name('billing.invoices.publish');
        Route::patch('/billing/invoices/{invoice}/void', [SuperAdminBillingInvoiceController::class, 'void'])->name('billing.invoices.void');
        Route::get('/billing/payments', [SuperAdminBillingPaymentController::class, 'index'])->name('billing.payments.index');
        Route::get('/billing/payments/{payment}', [SuperAdminBillingPaymentController::class, 'show'])->name('billing.payments.show');
        Route::post('/billing/payments', [SuperAdminBillingPaymentController::class, 'store'])->name('billing.payments.store');
        Route::get('/billing/payment-attempts', [SuperAdminBillingPaymentAttemptController::class, 'index'])->name('billing.payment-attempts.index');
        Route::get('/billing/payment-attempts/{paymentAttempt}', [SuperAdminBillingPaymentAttemptController::class, 'show'])->name('billing.payment-attempts.show');
        Route::get('/billing/provider-events', [SuperAdminProviderEventController::class, 'index'])->name('billing.provider-events.index');
        Route::get('/billing/provider-events/{providerEvent}', [SuperAdminProviderEventController::class, 'show'])->name('billing.provider-events.show');
        Route::patch('/billing/provider-events/{providerEvent}/retry', [SuperAdminProviderEventController::class, 'retry'])->name('billing.provider-events.retry');
    });

// Internal component gallery (dev reference) — no data, but staff-only (not public).
Route::get('/design-system', function () {
    return Inertia::render('DesignSystem');
})->middleware(['auth', 'hotel_host'])->name('design-system');

// ===== PMS (authenticated) =====
Route::middleware(['auth', 'hotel_host'])->prefix('pms')->group(function () {
    Route::get('/global-search', GlobalSearchController::class)
        ->middleware('throttle:120,1')
        ->name('global-search');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::middleware('permission:view_settings')->group(function () {
        Route::get('/lora-ai', [LoraAiController::class, 'index'])->name('lora-ai.index');
        Route::put('/lora-ai', [LoraAiController::class, 'update'])->name('lora-ai.update');
        Route::delete('/lora-ai/connection', [LoraAiController::class, 'disconnect'])->name('lora-ai.disconnect');
    });

    Route::redirect('/maintenance-design', '/pms/maintenance')->name('maintenance.design');

    Route::middleware('permission:view_maintenance')->group(function () {
        Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
        Route::get('/maintenance/attachments/{attachment}', [MaintenanceController::class, 'previewAttachment'])->name('maintenance.attachments.show');
        Route::post('/maintenance', [MaintenanceController::class, 'store'])->middleware('permission:create_maintenance')->name('maintenance.store');
        Route::patch('/maintenance/{maintenanceIssue}', [MaintenanceController::class, 'update'])->middleware('permission:update_maintenance')->name('maintenance.update');
        Route::patch('/maintenance/{maintenanceIssue}/assign', [MaintenanceController::class, 'assign'])->middleware('permission:update_maintenance')->name('maintenance.assign');
        Route::patch('/maintenance/{maintenanceIssue}/status', [MaintenanceController::class, 'updateStatus'])->middleware('permission:update_maintenance')->name('maintenance.status');
        Route::patch('/maintenance/{maintenanceIssue}/room-block', [MaintenanceController::class, 'updateRoomBlock'])->middleware('permission:update_maintenance')->name('maintenance.room-block');
        Route::post('/maintenance/{maintenanceIssue}/attachments', [MaintenanceController::class, 'storeAttachment'])->middleware('permission:update_maintenance')->name('maintenance.attachments.store');
        Route::delete('/maintenance/attachments/{attachment}', [MaintenanceController::class, 'destroyAttachment'])->middleware('permission:update_maintenance')->name('maintenance.attachments.destroy');
    });

    // Notifications (new-reservation bell) — any authenticated staff
    Route::get('/notifications/reservations', [NotificationController::class, 'reservations'])->name('notifications.reservations');

    // Room Management
    Route::middleware('permission:view_rooms')->group(function () {
        Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
        Route::post('/rooms', [RoomController::class, 'store'])->middleware('permission:create_rooms')->name('rooms.store');
        Route::put('/rooms/{room}', [RoomController::class, 'update'])->middleware('permission:update_rooms')->name('rooms.update');
        Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->middleware('permission:delete_rooms')->name('rooms.destroy');
        Route::patch('/rooms/{room}/status', [RoomController::class, 'updateStatus'])->middleware('permission:update_rooms')->name('rooms.status');
    });

    // Guest Profiles
    Route::middleware('permission:view_guests')->group(function () {
        Route::get('/guests', [GuestController::class, 'index'])->name('guests.index');
        Route::get('/guests/profile-design', fn () => Inertia::render('Guests/ProfileDesign'))->name('guests.profile-design');
        Route::middleware(['permission:update_guests', 'permission:delete_guests'])->group(function () {
            Route::get('/guests/{guest}/merge/{duplicate}', [GuestMergeController::class, 'show'])->name('guests.merge.show');
            Route::post('/guests/{guest}/merge/{duplicate}/suggest', [GuestMergeController::class, 'suggest'])->name('guests.merge.suggest');
            Route::post('/guests/{guest}/merge/{duplicate}', [GuestMergeController::class, 'store'])->name('guests.merge.store');
        });
        Route::get('/guests/{guest}', [GuestController::class, 'show'])->name('guests.show');
        Route::post('/guests', [GuestController::class, 'store'])->middleware('permission:create_guests')->name('guests.store');
        Route::put('/guests/{guest}', [GuestController::class, 'update'])->middleware('permission:update_guests')->name('guests.update');
        Route::delete('/guests/{guest}', [GuestController::class, 'destroy'])->middleware('permission:delete_guests')->name('guests.destroy');

        // Identity documents (passport/ID/…) — private storage, served only here.
        Route::post('/guests/{guest}/documents', [GuestController::class, 'storeDocument'])->middleware('permission:update_guests')->name('guests.documents.store');
        Route::post('/guests/{guest}/documents/{document}/analyze', [GuestController::class, 'analyzeDocument'])->middleware('permission:update_guests')->name('guests.documents.analyze');
        Route::put('/guests/{guest}/documents/{document}/apply-ai', [GuestController::class, 'applyDocumentAnalysis'])->middleware('permission:update_guests')->name('guests.documents.apply-ai');
        Route::get('/guests/documents/{document}', [GuestController::class, 'downloadDocument'])->name('guests.documents.show');
        Route::delete('/guests/documents/{document}', [GuestController::class, 'destroyDocument'])->middleware('permission:update_guests')->name('guests.documents.destroy');
    });

    // Reservations
    Route::middleware('permission:view_reservations')->group(function () {
        Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');

        // Guest messaging (Channex Messages) — front desk replies to OTA guests.
        Route::get('/messages', [MessagesController::class, 'index'])->middleware(['permission:view_reservations', 'module:channel_manager'])->name('messages.index');
        Route::get('/messages/unread', [MessagesController::class, 'unread'])->middleware(['permission:view_reservations', 'module:channel_manager'])->name('messages.unread');
        Route::post('/messages/{thread}/reply', [MessagesController::class, 'reply'])->middleware(['permission:view_reservations', 'module:channel_manager'])->name('messages.reply');
        Route::post('/messages/quick-replies', [MessagesController::class, 'saveQuickReplies'])->middleware(['permission:view_reservations', 'module:channel_manager'])->name('messages.quick-replies');
        Route::post('/messages/{thread}/close', [MessagesController::class, 'close'])->middleware(['permission:view_reservations', 'module:channel_manager'])->name('messages.close');
        Route::post('/messages/{thread}/reopen', [MessagesController::class, 'reopen'])->middleware(['permission:view_reservations', 'module:channel_manager'])->name('messages.reopen');
        Route::get('/reservations/calendar', [ReservationController::class, 'calendar'])->name('reservations.calendar');
        Route::get('/reservations/calendar-design', fn () => Inertia::render('Reservations/CalendarDesign'))->name('reservations.calendar-design');
        // Seasonal price quote for the create/edit form (server-computed; MUST stay before the {reservation} wildcard).
        Route::get('/reservations/quote', [ReservationController::class, 'quote'])->name('reservations.quote');
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
        Route::post('/reservations', [ReservationController::class, 'store'])->middleware('permission:create_reservations')->name('reservations.store');
        Route::post('/reservations/store-multi', [ReservationController::class, 'storeMulti'])->middleware('permission:create_reservations')->name('reservations.store-multi');
        Route::put('/reservations/{reservation}', [ReservationController::class, 'update'])->middleware('permission:update_reservations')->name('reservations.update');
        Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy'])->middleware('permission:delete_reservations')->name('reservations.destroy');
        Route::post('/reservations/{reservation}/check-in', [ReservationController::class, 'checkIn'])->middleware('permission:update_reservations')->name('reservations.check-in');
        Route::post('/reservations/{reservation}/check-out', [ReservationController::class, 'checkOut'])->middleware('permission:update_reservations')->name('reservations.check-out');
        // Front desk asks housekeeping for a stayover (daily) clean while the guest is in-house.
        Route::post('/reservations/{reservation}/request-cleaning', [ReservationController::class, 'requestCleaning'])->middleware(['module:housekeeping', 'permission:update_reservations'])->name('reservations.request-cleaning');
        Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->middleware('permission:update_reservations')->name('reservations.cancel');
        Route::post('/reservations/{reservation}/move-room', [ReservationController::class, 'moveRoom'])->middleware('permission:update_reservations')->name('reservations.move-room');
        Route::post('/reservations/{reservation}/resolve-conflict', [ReservationController::class, 'resolveConflict'])->middleware('permission:update_reservations')->name('reservations.resolve-conflict');
        Route::post('/reservations/{reservation}/folio', [ReservationController::class, 'addFolioLine'])->middleware('permission:update_reservations')->name('reservations.folio.add');
        Route::post('/reservations/{reservation}/folio/inventory', [ReservationController::class, 'addInventoryFolioLine'])
            ->middleware(['module:finance', 'permission:update_reservations'])->name('reservations.folio.inventory');
        Route::post('/reservations/{reservation}/payment', [ReservationController::class, 'recordPayment'])->middleware('permission:update_reservations')->name('reservations.payment');
        Route::post('/reservations/{reservation}/fiscalize', [ReservationFiscalizationController::class, 'store'])
            ->middleware(['module:finance', 'permission:update_reservations', 'throttle:10,1'])
            ->name('reservations.fiscalize');
    });

    // Housekeeping
    Route::middleware(['module:housekeeping', 'permission:view_housekeeping'])->group(function () {
        Route::get('/housekeeping', [CleaningTaskController::class, 'index'])->name('housekeeping.index');
        Route::get('/housekeeping/{cleaningTask}/clean', [CleaningTaskController::class, 'clean'])->name('housekeeping.clean');
        Route::post('/housekeeping', [CleaningTaskController::class, 'store'])->middleware('permission:create_housekeeping')->name('housekeeping.store');
        Route::patch('/housekeeping/{cleaningTask}/status', [CleaningTaskController::class, 'updateStatus'])->middleware('permission:update_housekeeping')->name('housekeeping.status');
        Route::patch('/housekeeping/{cleaningTask}/checklist', [CleaningTaskController::class, 'updateChecklist'])->middleware('permission:update_housekeeping')->name('housekeeping.checklist');
        Route::patch('/housekeeping/{cleaningTask}/assign', [CleaningTaskController::class, 'assign'])->middleware('permission:update_housekeeping')->name('housekeeping.assign');
        Route::post('/housekeeping/{cleaningTask}/issue', [CleaningTaskController::class, 'reportIssue'])->middleware('permission:update_housekeeping')->name('housekeeping.issue');
    });

    // POS Bar/Restaurant
    Route::middleware(['module:pos', 'permission:view_pos_orders'])->group(function () {
        Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
        Route::get('/pos/orders', [PosController::class, 'index'])->defaults('view', 'orders')->name('pos.orders');
        Route::get('/pos/receipts', [PosController::class, 'index'])->defaults('view', 'receipts')->name('pos.receipts');
        Route::get('/pos/shifts', [PosController::class, 'index'])->defaults('view', 'shifts')->name('pos.shifts');
        Route::post('/pos', [PosController::class, 'store'])->middleware('permission:create_pos_orders')->name('pos.store');
        Route::put('/pos/{posOrder}', [PosController::class, 'update'])->middleware('permission:update_pos_orders')->name('pos.update');
        Route::post('/pos/{posOrder}/complete', [PosController::class, 'complete'])->middleware('permission:update_pos_orders')->name('pos.complete');
        Route::post('/pos/{posOrder}/fiscalize', [PosController::class, 'fiscalize'])
            ->middleware(['module:finance', 'permission:update_pos_orders', 'throttle:10,1'])
            ->name('pos.fiscalize');
        Route::post('/pos/{posOrder}/cancel', [PosController::class, 'cancel'])->middleware('permission:update_pos_orders')->name('pos.cancel');
        Route::post('/pos/{posOrder}/refund', [PosController::class, 'refund'])->middleware('permission:update_pos_orders')->name('pos.refund');

        // Cash-drawer shifts (hapje/mbyllje turni)
        Route::post('/pos/shift/open', [PosShiftController::class, 'open'])->middleware('permission:open_pos_shift')->name('pos.shift.open');
        Route::post('/pos/shift/{posShift}/close', [PosShiftController::class, 'close'])->middleware('permission:close_pos_shift')->name('pos.shift.close');
    });

    // Reports
    Route::middleware('permission:view_reports')->group(function () {
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
        Route::get('/reports/executive', [ReportsController::class, 'executive'])->name('reports.executive');
        Route::get('/reports/channels', [ReportsController::class, 'channels'])->name('reports.channels');
        Route::get('/reports/outstanding', [ReportsController::class, 'outstanding'])->name('reports.outstanding');
        Route::get('/reports/shifts', [ReportsController::class, 'shifts'])->name('reports.shifts');
        Route::get('/reports/guests', [ReportsController::class, 'guests'])->name('reports.guests');
        Route::get('/reports/pos-sales', [ReportsController::class, 'posSales'])->middleware('module:pos')->name('reports.posSales');
        Route::get('/reports/arrivals', [ReportsController::class, 'arrivalsManifest'])->name('reports.arrivalsManifest');
        Route::get('/reports/departures', [ReportsController::class, 'departuresManifest'])->name('reports.departuresManifest');
        Route::get('/reports/pace', [ReportsController::class, 'pace'])->name('reports.pace');
        Route::get('/reports/cancellations', [ReportsController::class, 'cancellations'])->name('reports.cancellations');
        Route::get('/reports/payments', [ReportsController::class, 'payments'])->name('reports.payments');
        Route::get('/reports/vat', [ReportsController::class, 'vat'])->name('reports.vat');
        Route::get('/reports/performance', [ReportsController::class, 'performance'])->name('reports.performance');
        Route::get('/reports/repeat-guests', [ReportsController::class, 'repeatGuests'])->name('reports.repeatGuests');
        Route::get('/reports/guest-segments', [ReportsController::class, 'guestSegments'])->name('reports.guestSegments');
        Route::get('/reports/nationality', [ReportsController::class, 'nationality'])->name('reports.nationality');
        Route::get('/reports/booking-behavior', [ReportsController::class, 'bookingBehavior'])->name('reports.bookingBehavior');
        Route::get('/reports/pos-hourly', [ReportsController::class, 'posHourly'])->middleware('module:pos')->name('reports.posHourly');
        Route::get('/reports/pos-payment-mix', [ReportsController::class, 'posPaymentMix'])->middleware('module:pos')->name('reports.posPaymentMix');
        Route::get('/reports/pos-voids', [ReportsController::class, 'posVoids'])->middleware('module:pos')->name('reports.posVoids');
        Route::get('/reports/room-status', [ReportsController::class, 'roomStatus'])->name('reports.roomStatus');
        Route::get('/reports/housekeeping', [ReportsController::class, 'housekeepingReport'])->middleware('module:housekeeping')->name('reports.housekeepingReport');
        Route::get('/reports/maintenance-sla', [ReportsController::class, 'maintenanceSla'])->name('reports.maintenanceSla');
        Route::get('/reports/recurring-maintenance', [ReportsController::class, 'recurringMaintenance'])->name('reports.recurringMaintenance');
        Route::get('/reports/room-readiness', [ReportsController::class, 'roomReadiness'])->name('reports.roomReadiness');
        Route::get('/reports/operations-executive', [ReportsController::class, 'operationsExecutive'])->name('reports.operationsExecutive');
        Route::get('/reports/guest-movements', [ReportsController::class, 'guestMovements'])->name('reports.guestMovements');
        Route::get('/reports/in-house', [ReportsController::class, 'inHouse'])->name('reports.inHouse');
        Route::get('/reports/discounts', [ReportsController::class, 'discounts'])->name('reports.discounts');
        Route::get('/reports/department-revenue', [ReportsController::class, 'departmentRevenue'])->name('reports.departmentRevenue');
    });

    // Finance (module #11): NOT admin-only — the view gate is view_finance and
    // every write carries its own permission (receptionist can record an
    // arkëtim; only pay_bills/manage_transfers roles can move money out).
    Route::prefix('finance')->middleware(['module:finance', 'permission:view_finance'])->group(function () {
        Route::get('/', [FinanceController::class, 'index'])->name('finance.index');
        Route::get('/accounts', [FinanceController::class, 'accounts'])->name('finance.accounts');
        Route::post('/accounts', [FinanceController::class, 'storeAccount'])->middleware('permission:manage_finance_settings')->name('finance.accounts.store');
        Route::put('/accounts/{account}/toggle', [FinanceController::class, 'toggleAccount'])->middleware('permission:manage_finance_settings')->name('finance.accounts.toggle');
        Route::get('/payments', [FinanceController::class, 'payments'])->name('finance.payments');
        Route::get('/payments/export', [FinanceController::class, 'exportPayments'])->name('finance.payments.export');
        Route::post('/payments', [FinanceController::class, 'storePayment'])->middleware('permission:create_payment')->name('finance.payments.store');
        Route::post('/transfers', [FinanceController::class, 'storeTransfer'])->middleware('permission:manage_transfers')->name('finance.transfers.store');
        Route::get('/invoices', [FinanceController::class, 'invoices'])->name('finance.invoices');

        // Phase 2: Blerjet (Bills) + Furnitorët
        Route::get('/bills/create', [FinanceController::class, 'createBill'])->middleware('permission:manage_bills')->name('finance.bills.create');
        Route::get('/bills', [FinanceController::class, 'bills'])->name('finance.bills');
        Route::get('/bills/{bill}/edit', [FinanceController::class, 'editBill'])->middleware('permission:manage_bills')->name('finance.bills.edit');
        Route::get('/bills/{bill}', [FinanceController::class, 'showBill'])->name('finance.bills.show');
        Route::post('/bills/import-ai/analyze', [FinanceController::class, 'analyzeBillDocument'])->middleware('permission:manage_bills')->name('finance.bills.import-ai.analyze');
        Route::post('/bills', [FinanceController::class, 'storeBill'])->middleware('permission:manage_bills')->name('finance.bills.store');
        Route::put('/bills/{bill}', [FinanceController::class, 'updateBill'])->middleware('permission:manage_bills')->name('finance.bills.update');
        Route::post('/bills/{bill}/receive', [FinanceController::class, 'receiveBill'])->middleware('permission:manage_inventory')->name('finance.bills.receive');
        Route::post('/bills/categories', [FinanceController::class, 'storeBillCategory'])->middleware('permission:manage_bills|manage_suppliers')->name('finance.bill-categories.store');
        Route::put('/bills/categories/{category}', [FinanceController::class, 'updateBillCategory'])->where('category', '.*')->middleware('permission:manage_bills|manage_suppliers')->name('finance.bill-categories.update');
        Route::delete('/bills/categories/{category}', [FinanceController::class, 'destroyBillCategory'])->where('category', '.*')->middleware('permission:manage_bills|manage_suppliers')->name('finance.bill-categories.destroy');
        Route::post('/bills/{bill}/pay', [FinanceController::class, 'payBill'])->middleware('permission:pay_bills')->name('finance.bills.pay');
        Route::get('/suppliers', [FinanceController::class, 'suppliers'])->name('finance.suppliers');
        Route::post('/suppliers', [FinanceController::class, 'storeSupplier'])->middleware('permission:manage_suppliers')->name('finance.suppliers.store');
        Route::put('/suppliers/{supplier}', [FinanceController::class, 'updateSupplier'])->middleware('permission:manage_suppliers')->name('finance.suppliers.update');
        Route::delete('/suppliers/{supplier}', [FinanceController::class, 'destroySupplier'])->middleware('permission:manage_suppliers')->name('finance.suppliers.destroy');
    });

    Route::prefix('inventory')->middleware(['module:finance', 'permission:view_inventory'])->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('/items', [InventoryController::class, 'items'])->name('inventory.items');
        Route::post('/items', [InventoryController::class, 'storeItem'])->middleware('permission:manage_inventory')->name('inventory.items.store');
        Route::put('/items/{item}', [InventoryController::class, 'updateItem'])->middleware('permission:manage_inventory')->name('inventory.items.update');
        Route::get('/warehouses', [InventoryController::class, 'warehouses'])->name('inventory.warehouses');
        Route::post('/warehouses', [InventoryController::class, 'storeWarehouse'])->middleware('permission:manage_inventory')->name('inventory.warehouses.store');
        Route::put('/warehouses/{warehouse}', [InventoryController::class, 'updateWarehouse'])->middleware('permission:manage_inventory')->name('inventory.warehouses.update');
        Route::post('/transfers', [InventoryController::class, 'transfer'])->middleware('permission:manage_inventory')->name('inventory.transfers.store');
    });

    // Admin-only: User Management + Settings
    Route::middleware('role:admin')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');

        // Roles & per-module CRUD permissions
        Route::post('/users/roles', [UserController::class, 'storeRole'])->name('users.roles.store');
        Route::put('/users/roles/{role}/permissions', [UserController::class, 'updateRolePermissions'])->name('users.roles.permissions');

        // Settings
        // Pricing (Cmimet) — seasons + per-type rate matrix
        Route::get('/pricing', [PricingController::class, 'index'])->name('pricing.index');
        Route::post('/pricing/seasons', [PricingController::class, 'storeSeason'])->name('pricing.seasons.store');
        Route::post('/pricing/seasons/copy/preview', [SeasonCopyController::class, 'preview'])->name('pricing.seasons.copy.preview');
        Route::post('/pricing/seasons/copy', [SeasonCopyController::class, 'apply'])->name('pricing.seasons.copy.apply');
        Route::put('/pricing/seasons/{season}', [PricingController::class, 'updateSeason'])->name('pricing.seasons.update');
        Route::delete('/pricing/seasons/{season}', [PricingController::class, 'destroySeason'])->name('pricing.seasons.destroy');
        Route::post('/pricing/rates', [PricingController::class, 'saveRates'])->name('pricing.rates.save');

        Route::middleware('module:smart_pricing')->group(function () {
            // Çmim Inteligjent — occupancy-based price suggestions (suggest-only; Apply writes a date override)
            Route::get('/pricing/smart', [SmartPricingController::class, 'index'])->name('pricing.smart.index');
            Route::post('/pricing/smart/apply', [SmartPricingController::class, 'apply'])->name('pricing.smart.apply');
            Route::post('/pricing/smart/remove', [SmartPricingController::class, 'remove'])->name('pricing.smart.remove');
            // AI Pricing Assistant — generate a reasoned plan (JSON) + apply one recommendation
            Route::post('/pricing/smart/apply-range', [SmartPricingController::class, 'applyRange'])->name('pricing.smart.apply-range');
            Route::post('/pricing/smart/explain', [SmartPricingController::class, 'explain'])->name('pricing.smart.explain');
            Route::post('/pricing/smart/ask', [SmartPricingController::class, 'ask'])->name('pricing.smart.ask');
            Route::post('/pricing/smart/events/suggest', [SmartPricingController::class, 'suggestEvents'])->name('pricing.smart.events.suggest');
            Route::post('/pricing/smart/events', [SmartPricingController::class, 'approveEvent'])->name('pricing.smart.events.approve');
            Route::put('/pricing/smart/events/{pricingEvent}', [SmartPricingController::class, 'updateEvent'])->name('pricing.smart.events.update');
            Route::delete('/pricing/smart/events/{pricingEvent}', [SmartPricingController::class, 'destroyEvent'])->name('pricing.smart.events.destroy');
            Route::post('/pricing/smart/report', [SmartPricingController::class, 'generateReport'])->name('pricing.smart.report');
            Route::post('/pricing/smart/autopilot', [SmartPricingController::class, 'updateAutopilot'])->name('pricing.smart.autopilot');
            Route::post('/pricing/smart/autopilot/revert/{log}', [SmartPricingController::class, 'revertAutopilot'])->name('pricing.smart.autopilot.revert');
            Route::post('/pricing/smart/strategy', [SmartPricingController::class, 'updateStrategy'])->name('pricing.smart.strategy');
            Route::put('/pricing/smart/bounds/{roomType}', [SmartPricingController::class, 'updateBounds'])->name('pricing.smart.bounds');
        });

        // Channel manager (Channex) — manual full re-sync
        Route::middleware('module:channel_manager')->group(function () {
            Route::post('/channex/sync', [ChannexController::class, 'sync'])->name('channex.sync');
            Route::post('/channex/sell-window/preview', [ChannexController::class, 'previewSellWindow'])->name('channex.sell-window.preview');
            Route::put('/channex/sell-window', [ChannexController::class, 'updateSellWindow'])->name('channex.sell-window.update');
        });

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings/hotel', [SettingsController::class, 'updateHotel'])->name('settings.hotel');
        Route::put('/settings/booking-policies', [SettingsController::class, 'updateBookingPolicies'])->name('settings.booking-policies');
        Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications');
        Route::post('/settings/website', [SettingsController::class, 'updateWebsite'])->name('settings.website');
        Route::post('/settings/about', [SettingsController::class, 'updateAbout'])->name('settings.about');
        Route::put('/settings/financial', [SettingsController::class, 'updateFinancial'])->name('settings.financial');
        Route::put('/settings/market-rates', [SettingsController::class, 'updateMarketRates'])->name('settings.market-rates');
        Route::put('/settings/currencies', [SettingsController::class, 'updateCurrencies'])->middleware('module:finance')->name('settings.currencies');
        Route::post('/settings/currencies/refresh', [SettingsController::class, 'refreshCurrencies'])->middleware('module:finance')->name('settings.currencies.refresh');
        Route::put('/settings/pricing-programs', [SettingsController::class, 'updatePricingPrograms'])->name('settings.pricing-programs');
        Route::put('/settings/housekeeping', [SettingsController::class, 'updateHousekeeping'])->middleware('module:housekeeping')->name('settings.housekeeping');
        Route::put('/settings/ai', [SettingsController::class, 'updateAi'])->name('settings.ai');
        Route::post('/settings/integrations/{provider}/test', [SettingsController::class, 'testIntegration'])
            ->whereIn('provider', ['fature_al'])->middleware('throttle:10,1')->name('settings.integrations.test');

        // Settings: Room Types
        // Settings: Floors (Katet)
        Route::post('/settings/floors', [SettingsController::class, 'storeFloor'])->name('settings.floors.store');
        Route::put('/settings/floors/{floor}', [SettingsController::class, 'updateFloor'])->name('settings.floors.update');
        Route::delete('/settings/floors/{floor}', [SettingsController::class, 'destroyFloor'])->name('settings.floors.destroy');

        // Settings: Amenities (master list)
        Route::post('/settings/amenities', [SettingsController::class, 'storeAmenity'])->name('settings.amenities.store');
        Route::delete('/settings/amenities/{amenity}', [SettingsController::class, 'destroyAmenity'])->name('settings.amenities.destroy');

        Route::post('/settings/room-types', [SettingsController::class, 'storeRoomType'])->name('settings.room-types.store');
        Route::put('/settings/room-types/{roomType}', [SettingsController::class, 'updateRoomType'])->name('settings.room-types.update');
        Route::delete('/settings/room-types/{roomType}', [SettingsController::class, 'destroyRoomType'])->name('settings.room-types.destroy');

        // Settings: Room Type Images
        Route::post('/settings/room-types/{roomType}/images', [SettingsController::class, 'uploadRoomTypeImages'])->name('settings.room-types.images.upload');
        Route::delete('/settings/room-type-images/{roomTypeImage}', [SettingsController::class, 'deleteRoomTypeImage'])->name('settings.room-types.images.delete');
        Route::post('/settings/room-types/{roomType}/images/reorder', [SettingsController::class, 'reorderRoomTypeImages'])->name('settings.room-types.images.reorder');

        // Settings: Menu
        Route::middleware('module:pos')->group(function () {
            Route::post('/settings/menu-categories', [SettingsController::class, 'storeMenuCategory'])->name('settings.menu-categories.store');
            Route::put('/settings/menu-categories/{menuCategory}', [SettingsController::class, 'updateMenuCategory'])->name('settings.menu-categories.update');
            Route::delete('/settings/menu-categories/{menuCategory}', [SettingsController::class, 'destroyMenuCategory'])->name('settings.menu-categories.destroy');
            Route::post('/settings/menu-items', [SettingsController::class, 'storeMenuItem'])->name('settings.menu-items.store');
            Route::put('/settings/menu-items/{menuItem}', [SettingsController::class, 'updateMenuItem'])->name('settings.menu-items.update');
            Route::patch('/settings/menu-items/{menuItem}/toggle', [SettingsController::class, 'toggleMenuItem'])->name('settings.menu-items.toggle');
            Route::delete('/settings/menu-items/{menuItem}', [SettingsController::class, 'destroyMenuItem'])->name('settings.menu-items.destroy');
        });
    });
});

require __DIR__.'/auth.php';
