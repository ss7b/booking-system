<?php

namespace App\Domain\Account;

use App\Models\PendingEmailChange;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

final class AccountCredentials
{
    public static function passwordRules(): array
    {
        return [
            'string',
            Password::min(12)->letters()->numbers(),
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail(__('profile.errors.password_format'));
                }
            },
        ];
    }

    public static function passwordMessages(): array
    {
        return [
            'password.letters' => __('profile.errors.password_letters'),
            'password.numbers' => __('profile.errors.password_numbers'),
        ];
    }

    /** Update credentials while the caller holds the user row lock. */
    public static function replacePassword(User $user, #[SensitiveParameter] string $password): void
    {
        $user->password = Hash::make($password);
        $user->setRememberToken(Str::random(60));
        $user->save();

        PendingEmailChange::query()->whereKey($user->getKey())->delete();
    }
}
