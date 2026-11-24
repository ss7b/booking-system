<?php

namespace App\Domain\Account;

use App\Mail\EmailChangeCompleted;
use App\Mail\VerifyEmailChange;
use App\Models\PendingEmailChange;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

final class ChangeAccountEmail
{
    private const VERIFICATION_ROUTE = 'filament.admin.profile.email.verify';

    public function __construct(private readonly AccountSecurityMail $mail) {}

    public function isAvailable(): bool
    {
        return $this->mail->isAvailable();
    }

    public function pendingFor(User $user): ?PendingEmailChange
    {
        $pending = PendingEmailChange::query()->find($user->getKey());

        return $this->matchesCurrentCredentials($pending, $user) ? $pending : null;
    }

    public function request(User $user, string $email, #[SensitiveParameter] string $currentPassword): void
    {
        if (! $this->isAvailable()) {
            throw ValidationException::withMessages(['email' => __('account_email.mail_unavailable')]);
        }

        if (RateLimiter::increment('account-email:request:'.$user->getKey(), 900) > 5) {
            throw ValidationException::withMessages(['email' => __('account_email.request_throttled')]);
        }

        $email = trim($email);
        Validator::make(['email' => $email], ['email' => ['required', 'string', 'email:rfc', 'max:255']])->validate();

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addMinutes(30);
        $url = $this->mail->urls()->temporarySignedRoute(self::VERIFICATION_ROUTE, $expiresAt, ['token' => $token]);

        DB::transaction(function () use ($user, $email, $currentPassword, $tokenHash, $expiresAt): void {
            $current = $this->lockAccount($user);
            if ($currentPassword === '' || strlen($currentPassword) > 4096 || ! Hash::check($currentPassword, $current->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => __('account_email.invalid_current_password')]);
            }

            $existing = User::query()->where('email', $email)->first(['id']);
            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'email' => __($existing->is($current) ? 'account_email.same_email' : 'account_email.duplicate_email'),
                ]);
            }

            PendingEmailChange::query()->updateOrCreate(['user_id' => $current->getKey()], [
                'email' => $email,
                'token_hash' => $tokenHash,
                'credential_hash' => $this->credentialHash($current),
                'expires_at' => $expiresAt,
            ]);
        });

        try {
            Mail::mailer('smtp')->to($email)->send(new VerifyEmailChange($url));
        } catch (Throwable $exception) {
            // A failed send must not delete a newer request that arrived during delivery.
            PendingEmailChange::query()->whereKey($user->getKey())->where('token_hash', $tokenHash)->delete();
            Log::warning('Account email change verification could not be sent.', ['exception' => $exception::class]);

            throw ValidationException::withMessages(['email' => __('account_email.delivery_failed')]);
        }
    }

    /** Return whether the security notice was sent to the previous address. */
    public function verify(User $user, #[SensitiveParameter] string $token): bool
    {
        abort_unless($this->isAvailable(), 503, __('account_email.mail_unavailable'));

        try {
            $previousEmail = DB::transaction(function () use ($user, $token): string {
                $current = $this->lockAccount($user);
                $pending = PendingEmailChange::query()->find($current->getKey());

                abort_unless(
                    $this->matchesCurrentCredentials($pending, $current)
                        && hash_equals($pending->token_hash, hash('sha256', $token)),
                    403,
                    __('account_email.invalid_link'),
                );

                abort_if(User::query()->where('email', $pending->email)->exists(), 403, __('account_email.invalid_link'));

                $previousEmail = $current->email;
                Password::broker()->deleteToken($current);
                $current->forceFill(['email' => $pending->email, 'email_verified_at' => now()])->save();
                $pending->delete();

                return $previousEmail;
            });
        } catch (UniqueConstraintViolationException) {
            // Another account can claim the same address after the availability check.
            abort(403, __('account_email.invalid_link'));
        }

        try {
            Mail::mailer('smtp')->to($previousEmail)->send(new EmailChangeCompleted);
        } catch (Throwable $exception) {
            Log::warning('Account email change security notice could not be sent.', ['exception' => $exception::class]);

            return false;
        }

        return true;
    }

    private function lockAccount(User $user): User
    {
        $current = User::query()->lockForUpdate()->find($user->getKey());
        abort_unless($current instanceof User && $current->canAccessPanel(Filament::getPanel('admin')), 403);

        return $current;
    }

    private function matchesCurrentCredentials(?PendingEmailChange $pending, User $user): bool
    {
        return $pending !== null
            && $pending->expires_at->isFuture()
            && hash_equals($pending->credential_hash, $this->credentialHash($user));
    }

    private function credentialHash(User $user): string
    {
        return hash_hmac('sha256', $user->getAuthPassword()."\0".$user->email, (string) config('app.key'));
    }
}
