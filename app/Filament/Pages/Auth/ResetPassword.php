<?php

namespace App\Filament\Pages\Auth;

use App\Domain\Account\AccountCredentials;
use App\Domain\Account\AccountSecurityMail;
use App\Domain\Account\ResetAccountPassword;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\PasswordResetResponse;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\ResetPassword as BaseResetPassword;
use Illuminate\Support\Facades\Password;

final class ResetPassword extends BaseResetPassword
{
    public function mount(?string $email = null, ?string $token = null): void
    {
        abort_unless(app(AccountSecurityMail::class)->isAvailable(), 503, __('password_reset.unavailable'));

        parent::mount($email, $token);
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        $reset = app(ResetAccountPassword::class);

        try {
            $reset->authorizeGuest();
            $password = $this->form->getState()['password'];
            $status = $reset->reset($this->email ?? '', $this->token ?? '', $password);

            if ($status === Password::PASSWORD_RESET) {
                Notification::make()->success()->title(__('password_reset.completed'))->send();

                return app(PasswordResetResponse::class);
            }

            Notification::make()->danger()->title(__(
                $status === Password::RESET_THROTTLED ? 'password_reset.throttled' : 'password_reset.invalid_link',
            ))->send();

            return null;
        } finally {
            $this->password = '';
            $this->passwordConfirmation = '';
        }
    }

    public function getTitle(): string
    {
        return __('password_reset.reset_action');
    }

    public function getHeading(): string
    {
        return $this->getTitle();
    }

    public function getResetPasswordFormAction(): Action
    {
        return parent::getResetPasswordFormAction()->label(__('password_reset.reset_action'));
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->extraInputAttributes(['dir' => 'ltr']);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('profile.fields.new_password'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rules(AccountCredentials::passwordRules(...))
            ->validationMessages(AccountCredentials::passwordMessages())
            ->same('passwordConfirmation')
            ->autocomplete('new-password');
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()
            ->label(__('profile.fields.confirm_password'))
            ->rule('string')
            ->maxLength(72)
            ->autocomplete('new-password');
    }
}
