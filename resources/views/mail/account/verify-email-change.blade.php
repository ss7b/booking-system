<x-mail::message>
# {{ __('account_email.verify_subject') }}

{{ __('account_email.verify_body') }}

<x-mail::button :url="$verificationUrl">
{{ __('account_email.verify_button') }}
</x-mail::button>

{{ __('account_email.verify_ignore') }}
</x-mail::message>
