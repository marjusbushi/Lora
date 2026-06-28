<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CleaningTaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosShiftController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebsiteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ===== PUBLIC WEBSITE =====
// Root is host-aware: the admin subdomain (admin.villamucho.com) goes straight
// to the back-office; every other host (apex, www, localhost) gets the public site.
Route::get('/', function (Request $request) {
    if (str_starts_with($request->getHost(), 'admin.')) {
        return redirect()->route('dashboard');
    }
    return app(WebsiteController::class)->home();
})->name('website.home');
Route::get('/rooms', [WebsiteController::class, 'rooms'])->name('website.rooms');
Route::get('/book', [WebsiteController::class, 'bookingForm'])->name('website.book');
Route::post('/book/check', [WebsiteController::class, 'checkAvailability'])->middleware('throttle:30,1')->name('website.book.check');
Route::post('/book', [WebsiteController::class, 'submitBooking'])->middleware('throttle:10,1')->name('website.book.submit');
Route::get('/book/confirmation/{token}', [WebsiteController::class, 'bookingConfirmation'])->name('website.booking.confirmation');
Route::get('/about', [WebsiteController::class, 'about'])->name('website.about');
Route::get('/contact', [WebsiteController::class, 'contact'])->name('website.contact');
Route::post('/contact', [WebsiteController::class, 'submitContact'])->middleware('throttle:5,1')->name('website.contact.submit');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/design-system', function () {
    return Inertia::render('DesignSystem');
})->name('design-system');

// ===== PMS (authenticated) =====
Route::middleware('auth')->prefix('pms')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

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
        Route::get('/guests/{guest}', [GuestController::class, 'show'])->name('guests.show');
        Route::post('/guests', [GuestController::class, 'store'])->middleware('permission:create_guests')->name('guests.store');
        Route::put('/guests/{guest}', [GuestController::class, 'update'])->middleware('permission:update_guests')->name('guests.update');
        Route::delete('/guests/{guest}', [GuestController::class, 'destroy'])->middleware('permission:delete_guests')->name('guests.destroy');
    });

    // Reservations
    Route::middleware('permission:view_reservations')->group(function () {
        Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/calendar', [ReservationController::class, 'calendar'])->name('reservations.calendar');
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
        Route::post('/reservations', [ReservationController::class, 'store'])->middleware('permission:create_reservations')->name('reservations.store');
        Route::put('/reservations/{reservation}', [ReservationController::class, 'update'])->middleware('permission:update_reservations')->name('reservations.update');
        Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy'])->middleware('permission:delete_reservations')->name('reservations.destroy');
        Route::post('/reservations/{reservation}/check-in', [ReservationController::class, 'checkIn'])->middleware('permission:update_reservations')->name('reservations.check-in');
        Route::post('/reservations/{reservation}/check-out', [ReservationController::class, 'checkOut'])->middleware('permission:update_reservations')->name('reservations.check-out');
        Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->middleware('permission:update_reservations')->name('reservations.cancel');
        Route::post('/reservations/{reservation}/folio', [ReservationController::class, 'addFolioLine'])->middleware('permission:update_reservations')->name('reservations.folio.add');
        Route::post('/reservations/{reservation}/payment', [ReservationController::class, 'recordPayment'])->middleware('permission:update_reservations')->name('reservations.payment');
    });

    // Housekeeping
    Route::middleware('permission:view_housekeeping')->group(function () {
        Route::get('/housekeeping', [CleaningTaskController::class, 'index'])->name('housekeeping.index');
        Route::post('/housekeeping', [CleaningTaskController::class, 'store'])->middleware('permission:create_housekeeping')->name('housekeeping.store');
        Route::patch('/housekeeping/{cleaningTask}/status', [CleaningTaskController::class, 'updateStatus'])->middleware('permission:update_housekeeping')->name('housekeeping.status');
        Route::patch('/housekeeping/{cleaningTask}/assign', [CleaningTaskController::class, 'assign'])->middleware('permission:update_housekeeping')->name('housekeeping.assign');
        Route::post('/housekeeping/{cleaningTask}/issue', [CleaningTaskController::class, 'reportIssue'])->middleware('permission:update_housekeeping')->name('housekeeping.issue');
    });

    // POS Bar/Restaurant
    Route::middleware('permission:view_pos_orders')->group(function () {
        Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
        Route::post('/pos', [PosController::class, 'store'])->middleware('permission:create_pos_orders')->name('pos.store');
        Route::post('/pos/{posOrder}/complete', [PosController::class, 'complete'])->middleware('permission:update_pos_orders')->name('pos.complete');
        Route::post('/pos/{posOrder}/cancel', [PosController::class, 'cancel'])->middleware('permission:update_pos_orders')->name('pos.cancel');

        // Cash-drawer shifts (hapje/mbyllje turni)
        Route::post('/pos/shift/open', [PosShiftController::class, 'open'])->middleware('permission:open_pos_shift')->name('pos.shift.open');
        Route::post('/pos/shift/{posShift}/close', [PosShiftController::class, 'close'])->middleware('permission:close_pos_shift')->name('pos.shift.close');
    });

    // Reports
    Route::middleware('permission:view_reports')->group(function () {
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    });

    // Admin-only: User Management + Settings
    Route::middleware('role:admin')->group(function () {
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
        Route::put('/pricing/seasons/{season}', [PricingController::class, 'updateSeason'])->name('pricing.seasons.update');
        Route::delete('/pricing/seasons/{season}', [PricingController::class, 'destroySeason'])->name('pricing.seasons.destroy');
        Route::post('/pricing/rates', [PricingController::class, 'saveRates'])->name('pricing.rates.save');

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings/hotel', [SettingsController::class, 'updateHotel'])->name('settings.hotel');
        Route::post('/settings/website', [SettingsController::class, 'updateWebsite'])->name('settings.website');
        Route::post('/settings/about', [SettingsController::class, 'updateAbout'])->name('settings.about');
        Route::put('/settings/financial', [SettingsController::class, 'updateFinancial'])->name('settings.financial');
        Route::put('/settings/housekeeping', [SettingsController::class, 'updateHousekeeping'])->name('settings.housekeeping');

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
        Route::post('/settings/menu-categories', [SettingsController::class, 'storeMenuCategory'])->name('settings.menu-categories.store');
        Route::put('/settings/menu-categories/{menuCategory}', [SettingsController::class, 'updateMenuCategory'])->name('settings.menu-categories.update');
        Route::delete('/settings/menu-categories/{menuCategory}', [SettingsController::class, 'destroyMenuCategory'])->name('settings.menu-categories.destroy');
        Route::post('/settings/menu-items', [SettingsController::class, 'storeMenuItem'])->name('settings.menu-items.store');
        Route::put('/settings/menu-items/{menuItem}', [SettingsController::class, 'updateMenuItem'])->name('settings.menu-items.update');
        Route::patch('/settings/menu-items/{menuItem}/toggle', [SettingsController::class, 'toggleMenuItem'])->name('settings.menu-items.toggle');
        Route::delete('/settings/menu-items/{menuItem}', [SettingsController::class, 'destroyMenuItem'])->name('settings.menu-items.destroy');
    });
});

require __DIR__.'/auth.php';
