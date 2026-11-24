<?php

namespace App\Filament\Pages\Auth;

use App\Domain\Account\AccountSecurityMail;
use App\Domain\Account\ResetAccountPassword;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;

final class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function mount(): void
    {
        abort_unless(app(AccountSecurityMail::class)->isAvailable(), 503, __('password_reset.unavailable'));

        parent::mount();
    }

    public function request(): void
    {
        $reset = app(ResetAccountPassword::class);
        $reset->authorizeGuest();
        $reset->request($this->form->getState()['email']);

        $this->form->fill();
        Notification::make()->success()->title(__('password_reset.requested'))->send();
    }

    public function getTitle(): string
    {
        return __('password_reset.request_title');
    }

    public function getHeading(): string
    {
        return $this->getTitle();
    }

    public function loginAction(): Action
    {
        return parent::loginAction()->label(__('password_reset.back_to_login'));
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->rule('string')->maxLength(255)->extraInputAttributes(['dir' => 'ltr']);
    }

    protected function getRequestFormAction(): Action
    {
        return parent::getRequestFormAction()->label(__('password_reset.request_action'));
    }
}
