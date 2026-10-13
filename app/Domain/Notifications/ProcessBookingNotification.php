<?php

namespace App\Domain\Notifications;

use App\Domain\Authorization\BusinessAccess;
use App\Enums\BookingStatus;
use App\Enums\BusinessRole;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

final class ProcessBookingNotification
{
    public function __construct(
        private readonly BusinessAccess $access,
        private readonly NotificationPolicy $policy,
    ) {}

    public function handle(string $eventName, array $payload): void
    {
        $type = $this->notificationType($eventName, $payload);
        $bookingId = $payload['booking_id'] ?? null;

        if ($type === null || ! is_string($bookingId)) {
            return;
        }

        $booking = Booking::query()->with('business')->find($bookingId);
        if (! $booking || ! $booking->business) {
            return;
        }

        $eventIdentity = (string) ($payload['event_id'] ?? hash('sha256', $eventName.':'.json_encode($payload)));

        $this->process($booking, $type, $eventIdentity, $payload);
    }

    public function sendReminder(Booking $booking, int $intervalMinutes, BusinessRole $recipientRole): void
    {
        DB::transaction(function () use ($booking, $intervalMinutes, $recipientRole): void {
            $booking = Booking::query()->with('business')->lockForUpdate()->find($booking->getKey());

            if (! $booking
                || ! $booking->business
                || $booking->status !== BookingStatus::Confirmed
                || $booking->starts_at->lessThanOrEqualTo(now())
                || $booking->starts_at->subMinutes($intervalMinutes)->greaterThan(now())) {
                return;
            }

            $configuredIntervals = DB::table('business_notification_settings')
                ->where('business_id', $booking->business_id)
                ->where('role', $recipientRole->value)
                ->where('notification_type', 'booking.reminder_due')
                ->where('enabled', true)
                ->value('reminder_intervals');

            $configuredIntervals = json_decode($configuredIntervals ?: '[1440,120]', true) ?: [1440, 120];
            if (! in_array($intervalMinutes, array_map('intval', $configuredIntervals), true)) {
                return;
            }

            $identity = 'reminder:'.$booking->getKey().':'.$booking->starts_at->format('U.u').':'.$intervalMinutes;
            $this->process($booking, 'booking.reminder_due', $identity, recipientRole: $recipientRole);
        });
    }

    private function process(
        Booking $booking,
        string $type,
        string $eventIdentity,
        array $eventContext = [],
        ?BusinessRole $recipientRole = null,
    ): void {
        DB::transaction(function () use ($booking, $type, $eventIdentity, $eventContext, $recipientRole): void {
            $business = $booking->business;

            User::query()
                ->where('is_active', true)
                ->whereHas('businesses', function ($businesses) use ($business, $recipientRole): void {
                    $businesses->whereKey($business->getKey());
                    if ($recipientRole) {
                        $businesses->where('business_user.role', $recipientRole->value);
                    }
                })
                ->orderBy('users.id')
                ->get()
                ->each(function (User $user) use ($business, $booking, $type, $eventIdentity, $eventContext, $recipientRole): void {
                    $role = $this->access->role($user, $business);

                    if (! $role
                        || ($recipientRole && $role !== $recipientRole)
                        || ! $this->eligible($user, $business, $booking, $type, $role, $eventContext)) {
                        return;
                    }

                    $mandatory = $this->policy->isMandatory($type, $role);
                    if (! $mandatory && ! $this->isEnabled($business, $user, $type, $role)) {
                        return;
                    }

                    UserNotification::query()->insertOrIgnore([
                        'id' => (string) str()->ulid(),
                        'business_id' => $business->getKey(),
                        'user_id' => $user->getKey(),
                        'booking_id' => $this->access->canViewBooking($user, $booking) ? $booking->getKey() : null,
                        'notification_type' => $type,
                        'idempotency_key' => hash('sha256', $eventIdentity.':'.$user->getKey()),
                        'data' => json_encode([]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
        });
    }

    private function notificationType(string $eventName, array $payload): ?string
    {
        return match ($eventName) {
            'BookingHeld' => 'booking.created',
            'BookingConfirmed' => array_key_exists('from_status', $payload)
                ? 'booking.confirmed'
                : 'booking.created',
            'BookingRescheduled' => 'booking.rescheduled',
            'BookingCancelled' => 'booking.cancelled',
            default => null,
        };
    }

    private function eligible(
        User $user,
        Business $business,
        Booking $booking,
        string $type,
        BusinessRole $role,
        array $eventContext = [],
    ): bool {
        if (! in_array($type, NotificationPolicy::TYPES, true)) {
            return false;
        }

        if ($this->access->canViewBooking($user, $booking)) {
            return $role === BusinessRole::Staff || $role->canManageBookings();
        }

        return $type === 'booking.rescheduled'
            && $this->wasAffectedByReschedule($user, $business, $role, $eventContext);
    }

    private function wasAffectedByReschedule(User $user, Business $business, BusinessRole $role, array $eventContext): bool
    {
        $locationIds = array_values(array_unique(array_filter([
            $eventContext['old_location_id'] ?? null,
            $eventContext['new_location_id'] ?? null,
        ])));
        if ($locationIds === []) {
            return false;
        }

        $hasAffectedBranchAccess = Location::query()
            ->where('business_id', $business->getKey())
            ->whereIn('id', $locationIds)
            ->get()
            ->contains(fn (Location $location): bool => $role === BusinessRole::Staff
                ? $this->access->canViewLocation($user, $location)
                : $this->access->canAccessLocation($user, $business, $location));

        if (! $hasAffectedBranchAccess) {
            return false;
        }

        if ($role !== BusinessRole::Staff) {
            return $role->canManageBookings();
        }

        if (! Staff::query()->where('business_id', $business->getKey())->where('user_id', $user->getKey())->exists()) {
            return false;
        }

        $staffUserIds = array_values(array_unique(array_filter([
            $eventContext['old_staff_user_id'] ?? null,
            $eventContext['new_staff_user_id'] ?? null,
        ])));

        if ($staffUserIds === []) {
            $staffIds = array_values(array_unique(array_filter([
                $eventContext['old_staff_id'] ?? null,
                $eventContext['new_staff_id'] ?? null,
            ])));
            $staffUserIds = Staff::query()->whereIn('id', $staffIds)->whereNotNull('user_id')->pluck('user_id')->all();
        }

        return in_array((string) $user->getKey(), array_map('strval', $staffUserIds), true);
    }

    private function isEnabled(Business $business, User $user, string $type, BusinessRole $role): bool
    {
        $businessRule = DB::table('business_notification_settings')
            ->where('business_id', $business->getKey())
            ->where('role', $role->value)
            ->where('notification_type', $type)
            ->value('enabled');
        $enabled = $businessRule === null
            ? $this->policy->defaultEnabled($type, $role)
            : (bool) $businessRule;

        $preference = DB::table('user_notification_preferences')
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->where('notification_type', $type)
            ->value('enabled');

        return $enabled && ($preference === null || (bool) $preference);
    }
}
