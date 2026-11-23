<?php

namespace App\Rules;

use App\Domain\Authorization\BusinessAccess;
use App\Models\Business;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Validation\ValidationRule;

class AccessibleCalendarId implements ValidationRule
{
    public function __construct(private readonly bool $managementScope) {}

    public static function forManagement(): self
    {
        return new self(true);
    }

    public static function forScheduling(): self
    {
        return new self(false);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $business instanceof Business || ! $user instanceof User || (! is_string($value) && ! is_int($value))) {
            $fail(__('messages.calendar_unavailable'));

            return;
        }

        $access = app(BusinessAccess::class);
        $calendars = $this->managementScope
            ? $access->calendarsForManagement($user, $business)
            : $access->calendars($user, $business);

        if (! $calendars->whereKey((string) $value)->exists()) {
            $fail(__('messages.calendar_unavailable'));
        }
    }
}
