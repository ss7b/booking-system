@php
    $account = $this->getUser();
    $emailChange = app(\App\Domain\Account\ChangeAccountEmail::class);
    $emailChangeAvailable = $emailChange->isAvailable();
    $pending = $emailChangeAvailable ? $emailChange->pendingFor($account) : null;
@endphp

<x-filament-panels::page.simple>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-filament::button tag="a" :href="filament()->getUrl()" color="gray">
            {{ __('profile.back_to_panel') }}
        </x-filament::button>

        @include('filament.components.locale-switcher')
    </div>

    <x-filament::section :heading="__('profile.personal_information')">
        <div class="grid gap-y-6">
            <x-filament-panels::form id="profile-personal-form" wire:submit="save">
                {{ $this->form }}

                <div>
                    <x-filament::button type="submit" wire:target="save">
                        {{ __('profile.save_changes') }}
                    </x-filament::button>
                </div>
            </x-filament-panels::form>

            <dl>
                <dt class="text-sm font-medium text-gray-950 dark:text-white">{{ __('profile.fields.email') }}</dt>
                <dd class="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-white">
                    <span class="break-all" dir="ltr">{{ $account->email }}</span>
                    <x-filament::badge :color="$account->email_verified_at ? 'success' : 'gray'">
                        {{ __($account->email_verified_at ? 'profile.email_verified' : 'profile.email_unverified') }}
                    </x-filament::badge>
                </dd>
            </dl>

            @if ($pending)
                <p role="status" class="text-sm text-gray-700 dark:text-gray-400">
                    {{ __('profile.email_pending') }}
                    <span class="break-all" dir="ltr">{{ $pending->email }}</span>
                </p>
            @endif

            @if ($emailChangeAvailable)
                <x-filament::section
                    :heading="__('profile.change_email')"
                    :description="__('profile.email_description')"
                    collapsible
                    collapsed
                >
                    <x-filament-panels::form id="profile-email-form" wire:submit="requestEmailChange">
                        {{ $this->emailForm }}

                        <div>
                            <x-filament::button type="submit" wire:target="requestEmailChange">
                                {{ __('profile.send_verification') }}
                            </x-filament::button>
                        </div>
                    </x-filament-panels::form>
                </x-filament::section>
            @else
                <p class="text-sm text-gray-700 dark:text-gray-400">{{ __('profile.email_unavailable') }}</p>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('profile.account_security')" :description="__('profile.password_description')">
        <x-filament-panels::form id="profile-password-form" wire:submit="updatePassword">
            {{ $this->passwordForm }}

            <div>
                <x-filament::button type="submit" wire:target="updatePassword">
                    {{ __('profile.change_password') }}
                </x-filament::button>
            </div>
        </x-filament-panels::form>
    </x-filament::section>
</x-filament-panels::page.simple>
