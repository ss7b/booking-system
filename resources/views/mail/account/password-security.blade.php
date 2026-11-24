<x-mail::message>
# {{ __('navigation.brand') }}

@if ($resetUrl !== null)
{{ __('password_reset.mail.requested') }}

<x-mail::button :url="$resetUrl">
{{ __('password_reset.reset_action') }}
</x-mail::button>

{{ __('password_reset.mail.expires', ['minutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]) }}

{{ __('password_reset.mail.ignore') }}
@else
{{ __('password_reset.mail.changed') }}

{{ __('password_reset.mail.changed_at', ['time' => $changedAt]) }}

{{ __('password_reset.mail.report') }}
@endif
</x-mail::message>
