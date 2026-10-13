<?php

namespace App\Domain\Notifications;

use App\Domain\Authorization\BusinessAccess;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class NotificationCenter
{
    public function __construct(
        private readonly BusinessAccess $access,
        private readonly NotificationPolicy $policy,
    ) {}

    public function query(User $user, Business $business): Builder
    {
        $visibleBookingIds = $this->access->bookings($user, $business)->select('bookings.id');

        return UserNotification::query()
            ->where('user_id', $user->getKey())
            ->where('business_id', $business->getKey())
            ->where(function ($query) use ($visibleBookingIds): void {
                $query->whereNull('booking_id')->orWhereIn('booking_id', $visibleBookingIds);
            });
    }

    public function markRead(User $user, Business $business, string $notificationId): UserNotification
    {
        $notification = $this->query($user, $business)->whereKey($notificationId)->firstOrFail();
        $notification->read_at ??= now();
        $notification->save();

        return $notification;
    }

    public function markAllRead(User $user, Business $business): int
    {
        return $this->query($user, $business)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<array{notification_type: string, mandatory: bool, enabled: bool}> */
    public function preferences(User $user, Business $business): array
    {
        $role = $this->access->role($user, $business);
        abort_unless($role, 403);

        return array_map(function (string $type) use ($user, $business, $role): array {
            $mandatory = $this->policy->isMandatory($type, $role);
            $businessValue = DB::table('business_notification_settings')
                ->where('business_id', $business->getKey())
                ->where('role', $role->value)
                ->where('notification_type', $type)
                ->value('enabled');
            $userValue = DB::table('user_notification_preferences')
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->where('notification_type', $type)
                ->value('enabled');
            $businessEnabled = $businessValue === null
                ? $this->policy->defaultEnabled($type, $role)
                : (bool) $businessValue;

            return [
                'notification_type' => $type,
                'mandatory' => $mandatory,
                'enabled' => $mandatory || ($businessEnabled && ($userValue === null || (bool) $userValue)),
            ];
        }, NotificationPolicy::TYPES);
    }

    public function savePreferences(User $user, Business $business, array $preferences): void
    {
        $role = $this->access->role($user, $business);
        abort_unless($role, 403);

        foreach ($preferences as $preference) {
            $type = $preference['notification_type'];
            if ($this->policy->isMandatory($type, $role)) {
                if (! $preference['enabled']) {
                    throw ValidationException::withMessages([
                        'preferences' => ["{$type} is mandatory for your business role."],
                    ]);
                }

                continue;
            }

            DB::table('user_notification_preferences')->updateOrInsert(
                [
                    'business_id' => $business->getKey(),
                    'user_id' => $user->getKey(),
                    'notification_type' => $type,
                ],
                ['enabled' => $preference['enabled'], 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    /** @return list<array{role: string, notification_type: string, mandatory: bool, enabled: bool, reminder_intervals: array}> */
    public function businessSettings(Business $business): array
    {
        $settings = DB::table('business_notification_settings')
            ->where('business_id', $business->getKey())
            ->get()
            ->keyBy(fn ($setting): string => $setting->role.':'.$setting->notification_type);

        $result = [];
        foreach (BusinessRole::cases() as $role) {
            foreach (NotificationPolicy::TYPES as $type) {
                $key = $role->value.':'.$type;
                $setting = $settings->get($key);
                $mandatory = $this->policy->isMandatory($type, $role);
                $result[] = [
                    'role' => $role->value,
                    'notification_type' => $type,
                    'mandatory' => $mandatory,
                    'enabled' => $mandatory || ($setting ? (bool) $setting->enabled : $this->policy->defaultEnabled($type, $role)),
                    'reminder_intervals' => $type === 'booking.reminder_due'
                        ? (json_decode($setting?->reminder_intervals ?? '[1440,120]', true) ?: [1440, 120])
                        : [],
                ];
            }
        }

        return $result;
    }

    public function saveBusinessSettings(Business $business, array $rules): void
    {
        foreach ($rules as $rule) {
            $role = BusinessRole::from($rule['role']);
            $type = $rule['notification_type'];
            $mandatory = $this->policy->isMandatory($type, $role);

            if ($mandatory && ! $rule['enabled']) {
                throw ValidationException::withMessages([
                    'rules' => ["{$type} is mandatory for {$role->value} members."],
                ]);
            }

            if ($type !== 'booking.reminder_due' && ! empty($rule['reminder_intervals'])) {
                throw ValidationException::withMessages([
                    'rules' => ['Reminder intervals are only valid for booking reminders.'],
                ]);
            }

            DB::table('business_notification_settings')->updateOrInsert(
                [
                    'business_id' => $business->getKey(),
                    'role' => $role->value,
                    'notification_type' => $type,
                ],
                [
                    'enabled' => $mandatory || $rule['enabled'],
                    'reminder_intervals' => $type === 'booking.reminder_due'
                        ? json_encode(array_values(array_unique($rule['reminder_intervals'] ?? [1440, 120])))
                        : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
