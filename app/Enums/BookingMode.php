<?php

namespace App\Enums;

enum BookingMode: string
{
    case StaffRequired = 'staff_required';
    case Staffless = 'staffless';
}
