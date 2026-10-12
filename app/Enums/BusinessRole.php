<?php

namespace App\Enums;

enum BusinessRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Receptionist = 'receptionist';
    case Staff = 'staff';

    public function canManageBusinessSettings(): bool
    {
        return $this === self::Owner;
    }

    public function canManageOperations(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }

    public function canManageBookings(): bool
    {
        return in_array($this, [self::Owner, self::Manager, self::Receptionist], true);
    }
}
