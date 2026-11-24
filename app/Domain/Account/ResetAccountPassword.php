<?php

namespace App\Domain\Account;

use App\Http\Middleware\SetFilamentLocale;
use App\Mail\AccountPasswordMail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

use function Illuminate\Support\defer;

final class ResetAccountPassword
{
    public function __construct(private readonly AccountSecurityMail $mail) {}

    public function request(string $email): void
    {
        $this->authorizeGuest();

        if ($this->limited('request', $email, ipLimit: 10, emailLimit: 3, emailWindow: 900)) {
            return;
        }

        $issued = (new Timebox)->call(fn (): ?array => DB::transaction(function () use ($email): ?array {
            $account = $this->lockEligibleAccount($email);
            if ($account === null) {
                return null;
            }

            $token = null;
            Password::broker()->sendResetLink(
                ['email' => $account->email],
                static function (User $user, #[SensitiveParameter] string $issuedToken) use (&$token): void {
                    $token = $issuedToken;
                },
            );

            return $token === null ? null : [$account, $token];
        }), (int) config('auth.timebox_duration', 200000));

        if ($issued === null) {
            return;
        }

        [$account, $token] = $issued;
        $url = $this->mail->urls()->signedRoute('filament.admin.auth.password-reset.reset', [
            'email' => $account->email,
            'token' => $token,
        ]);
        $locale = $this->localeFor($account);

        // Keep SMTP out of the public response and out of the user lock.
        defer(function () use ($account, $token, $url, $locale): void {
            $current = $account->fresh();
            if ($current?->email !== $account->email || ! Password::broker()->tokenExists($current, $token)) {
                return;
            }

            if ($this->deliver($current->email, new AccountPasswordMail(resetUrl: $url), $locale)) {
                event(new PasswordResetLinkSent($current));
            }
        });
    }

    public function reset(
        string $email,
        #[SensitiveParameter] string $token,
        #[SensitiveParameter] string $password,
    ): string {
        $this->authorizeGuest();
        if ($this->limited('reset', $email, ipLimit: 30, emailLimit: 5, emailWindow: 300)) {
            return Password::RESET_THROTTLED;
        }

        $account = DB::transaction(function () use ($email, $token, $password): ?User {
            $account = $this->lockEligibleAccount($email);
            if ($account === null) {
                return null;
            }

            // Profile updates, email confirmation and broker validation serialize on this user row.
            $status = Password::broker()->reset([
                'email' => $account->email,
                'token' => $token,
                'password' => $password,
            ], static function (User $user, #[SensitiveParameter] string $newPassword) use ($account): void {
                if (Hash::check($newPassword, $account->getAuthPassword())) {
                    throw ValidationException::withMessages(['password' => __('profile.errors.password_reused')]);
                }

                AccountCredentials::replacePassword($account, $newPassword);
            });

            return $status === Password::PASSWORD_RESET ? $account : null;
        });

        if ($account === null) {
            return Password::INVALID_TOKEN;
        }

        event(new PasswordReset($account));
        $locale = $this->localeFor($account);
        $changedAt = now()->format('Y-m-d H:i (P)');
        defer(fn () => $this->deliver($account->email, new AccountPasswordMail(changedAt: $changedAt), $locale));

        return Password::PASSWORD_RESET;
    }

    public function authorizeGuest(): void
    {
        abort_unless($this->mail->isAvailable(), 503, __('password_reset.unavailable'));
        abort_if(Filament::auth()->check(), 403);
    }

    private function limited(string $action, string $email, int $ipLimit, int $emailLimit, int $emailWindow): bool
    {
        if (RateLimiter::increment('account-password:'.$action.':ip:'.request()->ip(), 60) > $ipLimit) {
            return true;
        }

        $key = hash('sha256', Str::lower(trim($email)));

        return RateLimiter::increment('account-password:'.$action.':email:'.$key, $emailWindow) > $emailLimit;
    }

    private function lockEligibleAccount(string $email): ?User
    {
        $account = User::query()->where('email', $email)->lockForUpdate()->first();

        return $account?->canAccessPanel(Filament::getPanel('admin')) ? $account : null;
    }

    private function localeFor(User $account): string
    {
        return in_array($account->preferred_locale, SetFilamentLocale::SUPPORTED_LOCALES, true)
            ? $account->preferred_locale
            : app()->getLocale();
    }

    private function deliver(string $email, AccountPasswordMail $message, string $locale): bool
    {
        try {
            Mail::mailer('smtp')->to($email)->locale($locale)->send($message);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Account password email delivery failed.', ['exception' => $exception::class]);

            return false;
        }
    }
}
