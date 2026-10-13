<?php

namespace App\Console\Commands;

use App\Domain\Notifications\ProcessBookingNotification;
use App\Enums\BookingStatus;
use App\Enums\BusinessRole;
use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchBookingReminders extends Command
{
    protected $signature = 'bookings:dispatch-reminders';

    protected $description = 'Create due in-app reminders for eligible booking members.';

    public function handle(ProcessBookingNotification $notifications): int
    {
        Booking::query()
            ->with('business')
            ->where('status', BookingStatus::Confirmed->value)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addYear())
            ->chunkById(200, function ($bookings) use ($notifications): void {
                foreach ($bookings->sortBy('starts_at') as $booking) {
                    $rules = DB::table('business_notification_settings')
                        ->where('business_id', $booking->business_id)
                        ->where('notification_type', 'booking.reminder_due')
                        ->where('enabled', true)
                        ->get();

                    foreach ($rules as $rule) {
                        $role = BusinessRole::tryFrom($rule->role);
                        if (! $role) {
                            continue;
                        }

                        $intervals = json_decode($rule->reminder_intervals ?: '[1440,120]', true) ?: [1440, 120];
                        foreach (array_unique(array_map('intval', $intervals)) as $interval) {
                            if ($booking->starts_at->greaterThan(now())
                                && $booking->starts_at->subMinutes($interval)->lessThanOrEqualTo(now())) {
                                $notifications->sendReminder($booking, $interval, $role);
                            }
                        }
                    }
                }
            });

        return self::SUCCESS;
    }
}
