<?php

namespace App\Filament\Pages\Auth;

use App\Domain\Account\AccountSecurityMail;
use Filament\Forms\Components\Component;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

final class Login extends BaseLogin
{
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->hint(
            app(AccountSecurityMail::class)->isAvailable()
                ? new HtmlString(Blade::render(
                    '<x-filament::link :href="$url" tabindex="3">{{ $label }}</x-filament::link>',
                    ['url' => filament()->getRequestPasswordResetUrl(), 'label' => __('password_reset.request_title')],
                ))
                : null,
        );
    }
}
