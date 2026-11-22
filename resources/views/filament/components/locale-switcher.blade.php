<form
    method="POST"
    action="{{ route('filament.locale.update') }}"
    class="flex items-center gap-2 px-2 py-1"
    aria-label="{{ __('Language') }}"
>
    @csrf
    <label for="filament-locale" class="sr-only">{{ __('Language') }}</label>
    <select
        id="filament-locale"
        name="locale"
        class="fi-select-input rounded-lg border-gray-300 bg-white py-1 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900"
        dir="ltr"
    >
        <option value="ar" @selected(app()->getLocale() === 'ar')>العربية</option>
        <option value="en" @selected(app()->getLocale() === 'en')>English</option>
    </select>
    <x-filament::button type="submit" size="sm">
        {{ __('Change language') }}
    </x-filament::button>
</form>
