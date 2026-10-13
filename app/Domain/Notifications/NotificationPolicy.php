<?php

namespace App\Domain\Notifications;

use App\Enums\BusinessRole;

final class NotificationPolicy
{
    public const TYPES = [
        'booking.created',
        'booking.confirmed',
        'booking.rescheduled',
        'booking.cancelled',
        'booking.reminder_due',
    ];

    public function isMandatory(string $type, BusinessRole $role): bool
    {
        return in_array($role, $this->mandatoryRoles($type), true);
    }

    /** @return list<BusinessRole> */
    public function mandatoryRoles(string $type): array
    {
        return match ($type) {
            'booking.created' => [BusinessRole::Owner, BusinessRole::Manager, BusinessRole::Receptionist],
            'booking.rescheduled', 'booking.cancelled' => [
                BusinessRole::Owner,
                BusinessRole::Manager,
                BusinessRole::Receptionist,
                BusinessRole::Staff,
            ],
            default => [],
        };
    }

    public function defaultEnabled(string $type, BusinessRole $role): bool
    {
        return match ($type) {
            'booking.confirmed' => in_array($role, [
                BusinessRole::Owner,
                BusinessRole::Manager,
                BusinessRole::Receptionist,
                BusinessRole::Staff,
            ], true),
            default => false,
        };
    }
}
