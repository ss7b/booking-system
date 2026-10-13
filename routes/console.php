<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:publish-outbox')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:dispatch-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('customer-notifications:dispatch-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('customer-notifications:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('customer-notifications:prune-attempts')->daily()->withoutOverlapping();
