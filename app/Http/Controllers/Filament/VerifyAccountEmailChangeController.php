<?php

namespace App\Http\Controllers\Filament;

use App\Domain\Account\ChangeAccountEmail;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;

final class VerifyAccountEmailChangeController
{
    public function __invoke(Request $request, #[SensitiveParameter] string $token, ChangeAccountEmail $change): RedirectResponse
    {
        abort_unless($request->isMethod('GET') && ! $request->prefetch(), 405, headers: ['Allow' => 'GET']);

        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        // Rate-limit rejected signatures as well as valid links.
        abort_if(RateLimiter::increment('account-email:verify:'.$user->getKey(), 60) > 10, 429, __('account_email.verification_throttled'));
        abort_unless($request->hasValidSignature(), 403, __('account_email.invalid_link'));

        $noticeSent = $change->verify($user, $token);
        Notification::make()->title(__('account_email.email_verified'))->success()->send();

        if (! $noticeSent) {
            Notification::make()->title(__('account_email.security_notice_failed'))->warning()->send();
        }

        return redirect()->route('filament.admin.auth.profile')->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
