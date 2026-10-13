<?php

use App\Http\Controllers\Api\V1\Admin\BusinessMembershipController;
use App\Http\Controllers\Api\V1\Admin\NotificationController;
use App\Http\Controllers\Api\V1\Public\AvailabilityController;
use App\Http\Controllers\Api\V1\Public\BookingController;
use App\Http\Controllers\Api\V1\Public\LocationController;
use App\Http\Controllers\Api\V1\Public\ServiceController;
use App\Http\Controllers\Api\V1\Public\StaffController;
use App\Http\Controllers\Webhooks\TwilioCustomerNotificationStatusController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureBusinessMembership;
use App\Http\Middleware\ResolvePublicBusiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/v1/customer-notification-attempts/{attempt}/twilio-status', TwilioCustomerNotificationStatusController::class)
    ->name('customer-notifications.twilio-status');

Route::prefix('v1')->middleware(ResolvePublicBusiness::class)->group(function () {
    Route::get('/services', [ServiceController::class, 'index'])->middleware('throttle:60,1');
    Route::get('/locations', [LocationController::class, 'index'])->middleware('throttle:60,1');
    Route::get('/staff', [StaffController::class, 'index'])->middleware('throttle:60,1');
    Route::get('/availability/calendar', [AvailabilityController::class, 'calendar'])->middleware('throttle:60,1');
    Route::get('/availability', AvailabilityController::class)->middleware('throttle:60,1');
    Route::post('/booking-holds', [BookingController::class, 'hold'])->middleware('throttle:10,1');
    Route::post('/bookings', [BookingController::class, 'storeDirect'])->middleware('throttle:10,1');
    Route::get('/bookings/{reference}', [BookingController::class, 'show'])->middleware('throttle:60,1');
    Route::post('/bookings/{reference}/confirm', [BookingController::class, 'confirm'])->middleware('throttle:10,1');
    Route::post('/bookings/{reference}/cancel', [BookingController::class, 'cancel'])->middleware('throttle:10,1');
    Route::post('/bookings/{reference}/reschedule', [BookingController::class, 'reschedule'])->middleware('throttle:10,1');
});

Route::prefix('v1/admin')
    ->middleware(['auth:sanctum', ResolvePublicBusiness::class, EnsureBusinessMembership::class])
    ->group(function () {
        Route::get('/memberships', [BusinessMembershipController::class, 'index']);
        Route::put('/memberships', [BusinessMembershipController::class, 'store']);
        Route::delete('/memberships/{user}', [BusinessMembershipController::class, 'destroy']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('/notification-preferences', [NotificationController::class, 'preferences']);
        Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences']);
        Route::get('/notification-settings', [NotificationController::class, 'settings']);
        Route::put('/notification-settings', [NotificationController::class, 'updateSettings']);
        Route::get('/bookings', [App\Http\Controllers\Api\V1\Admin\BookingController::class, 'index']);
        Route::get('/bookings/{booking}', [App\Http\Controllers\Api\V1\Admin\BookingController::class, 'show']);
        Route::post('/bookings/{booking}/reschedule', [App\Http\Controllers\Api\V1\Admin\BookingController::class, 'reschedule']);
        Route::post('/bookings/{booking}/{action}', [App\Http\Controllers\Api\V1\Admin\BookingController::class, 'transition'])
            ->whereIn('action', ['confirm', 'cancel', 'check-in', 'complete', 'no-show']);
    });

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', EnsureActiveUser::class]);
