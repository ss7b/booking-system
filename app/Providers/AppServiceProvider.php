<?php

namespace App\Providers;

use App\Domain\Notifications\CustomerNotificationService;
use App\Domain\Notifications\ProcessBookingNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['BookingHeld', 'BookingConfirmed', 'BookingRescheduled', 'BookingCancelled'] as $eventName) {
            Event::listen($eventName, function (array $payload) use ($eventName): void {
                app(ProcessBookingNotification::class)->handle($eventName, $payload);
                app(CustomerNotificationService::class)->processOutboxEvent($eventName, $payload);
            });
        }
    }
}
