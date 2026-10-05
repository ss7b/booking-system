<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Expired = 'expired';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Held => [self::Confirmed, self::Cancelled, self::Expired],
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::Completed, self::NoShow],
            self::Completed, self::Cancelled, self::NoShow, self::Expired => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}