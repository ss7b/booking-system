<div class="grid gap-y-4">
    <x-filament::link :href="filament()->getLoginUrl()">{{ __('password_reset.back_to_login') }}</x-filament::link>
    <x-filament::link :href="filament()->getRequestPasswordResetUrl()">{{ __('password_reset.request_another') }}</x-filament::link>
    @include('filament.components.locale-switcher')
</div>
