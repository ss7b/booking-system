<x-filament-panels::page>
    <div wire:poll.10s="refreshInbox" class="space-y-6">
        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            {{ $this->table }}
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white/80 shadow-sm dark:border-gray-700 dark:bg-gray-900/40">
            <div class="border-b border-gray-200 px-4 py-4 sm:px-5 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('notifications.ui.my_preferences') }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('notifications.ui.preference_scope') }}</p>
            </div>
            <form wire:submit="savePreferences" class="space-y-4 p-4 sm:p-5">
                <div class="space-y-2 rounded-xl border border-gray-200 bg-gray-50/60 dark:border-gray-700 dark:bg-gray-950/20">
                    @foreach (\App\Domain\Notifications\NotificationPolicy::TYPES as $type)
                        @php($key = str_replace('.', '_', $type))
                        @php($mandatory = in_array($type, $mandatoryTypes, true))
                        <label class="flex items-center justify-between gap-4 rounded-lg px-3 py-3 sm:px-4 {{ $mandatory ? 'bg-primary-50/40 dark:bg-primary-500/5' : 'bg-transparent' }}">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('notifications.types.'.str_replace('.', '_', $type)) }}</span>
                                @if ($mandatory)
                                    <span class="mt-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ __('notifications.ui.required') }}</span>
                                @endif
                            </span>
                            <div class="shrink-0">
                                <input type="checkbox" wire:model="preferences.{{ $key }}" @disabled($mandatory) class="h-4 w-4 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 disabled:opacity-60">
                            </div>
                        </label>
                    @endforeach
                </div>
                <x-filament::button type="submit" icon="heroicon-o-check" wire:target="savePreferences" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="savePreferences">{{ __('notifications.ui.save_preferences') }}</span>
                        <span wire:loading wire:target="savePreferences">{{ __('notifications.ui.saving') }}</span>
                </x-filament::button>
            </form>
        </section>

        @if (count($settings))
            <section class="rounded-2xl border border-gray-200 bg-white/80 shadow-sm dark:border-gray-700 dark:bg-gray-900/40">
                <div class="border-b border-gray-200 px-4 py-4 sm:px-5 dark:border-gray-700">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('notifications.ui.business_rules') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('notifications.ui.mandatory_rules') }}</p>
                </div>
                <form wire:submit="saveSettings" class="space-y-4 p-4 sm:p-5">
                    <div class="space-y-2 rounded-xl border border-gray-200 bg-gray-50/60 dark:border-gray-700 dark:bg-gray-950/20">
                        @foreach ($settings as $index => $setting)
                            <div class="grid gap-3 rounded-lg border border-transparent px-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(12rem,0.8fr)] sm:items-center sm:px-4 {{ $setting['mandatory'] ? 'bg-primary-50/30 dark:bg-primary-500/5' : 'bg-transparent' }}">
                                <div class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('notifications.types.'.str_replace('.', '_', $setting['notification_type'])) }}</span>
                                    <span class="mt-1 inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('roles.'.$setting['role']) }}
                                        @if ($setting['mandatory'])
                                            <span class="inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ __('notifications.ui.required') }}</span>
                                        @endif
                                    </span>
                                </div>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                    <input type="checkbox" wire:model="settings.{{ $index }}.enabled" @disabled($setting['mandatory']) class="h-4 w-4 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 disabled:opacity-60">
                                    {{ __('notifications.ui.enabled') }}
                                </label>
                                @if ($setting['notification_type'] === 'booking.reminder_due')
                                    <label class="block text-sm">
                                        <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('notifications.ui.reminder_intervals') }}</span>
                                        <input type="text" wire:model="settings.{{ $index }}.reminder_intervals" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500" placeholder="1440, 120">
                                    </label>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <x-filament::button type="submit" icon="heroicon-o-check" wire:target="saveSettings" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveSettings">{{ __('notifications.ui.save_rules') }}</span>
                        <span wire:loading wire:target="saveSettings">{{ __('notifications.ui.saving') }}</span>
                    </x-filament::button>
                </form>
            </section>
        @endif
    </div>
</x-filament-panels::page>