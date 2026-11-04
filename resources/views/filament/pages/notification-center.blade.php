<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-2xl border border-gray-200 bg-white/80 shadow-sm dark:border-gray-700 dark:bg-gray-900/40">
            <div class="flex flex-col gap-4 border-b border-gray-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5 dark:border-gray-700">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Inbox</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $unreadCount }} unread · {{ $notifications->total() }} notifications</p>
                </div>
                <x-filament::button color="gray" icon="heroicon-o-check" wire:click="markAllRead" wire:target="markAllRead" wire:loading.attr="disabled">
                    Mark all as read
                </x-filament::button>
            </div>

            @if ($notifications->isEmpty())
                <div class="flex flex-col items-center justify-center gap-2 px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 2a5 5 0 0 1 5 5v2.879l1.146 2.292A1 1 0 0 1 15.18 13H4.82a1 1 0 0 1-.966-1.829L5 9.879V7a5 5 0 0 1 5-5Zm0 15a2.5 2.5 0 0 1-2.45-2h4.9A2.5 2.5 0 0 1 10 17Z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <p class="font-medium text-gray-700 dark:text-gray-200">No notifications yet.</p>
                    <p class="max-w-sm text-xs text-gray-500 dark:text-gray-400">When new booking updates arrive, they will appear here.</p>
                </div>
            @else
                <div class="space-y-3 p-3 sm:p-4">
                    @foreach ($notifications as $notification)
                        <article class="flex flex-col gap-3 rounded-xl border px-3 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-4 {{ $notification->read_at ? 'border-gray-200 bg-gray-50/70 dark:border-gray-700 dark:bg-gray-950/30' : 'border-primary-200 bg-primary-50/40 dark:border-primary-500/30 dark:bg-primary-500/5' }}">
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="mt-0.5 flex h-2.5 w-2.5 shrink-0 rounded-full {{ $notification->read_at ? 'bg-gray-300 dark:bg-gray-600' : 'bg-primary-500' }}"></div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ str($notification->notification_type)->after('booking.')->replace('_', ' ')->headline() }}</p>
                                        @if (! $notification->read_at)
                                            <span class="inline-flex items-center rounded-full bg-primary-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">Unread</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-gray-600 dark:bg-gray-700 dark:text-gray-300">Read</span>
                                        @endif
                                    </div>
                                    @if (data_get($notification->data, 'message'))
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ data_get($notification->data, 'message') }}</p>
                                    @elseif ($notification->booking)
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                            <span class="font-medium text-gray-700 dark:text-gray-200">{{ $notification->booking->reference }}</span>
                                            <span class="mx-1 text-gray-400" aria-hidden="true">·</span>
                                            <span>{{ $notification->booking->starts_at->timezone($notification->booking->location?->timezone ?: $business->timezone)->format('M j, Y H:i') }}</span>
                                        </p>
                                    @else
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">The related booking is no longer available.</p>
                                    @endif
                                    <time class="mt-2 block text-xs text-gray-500 dark:text-gray-400" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                                </div>
                            </div>
                            <div class="flex items-center justify-end gap-2 sm:min-w-[13rem]">
                                @if ($notification->booking_url)
                                    <a class="text-sm font-medium text-primary-600 transition hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300" href="{{ $notification->booking_url }}">Open booking</a>
                                @endif
                                @if (! $notification->read_at)
                                    <x-filament::icon-button icon="heroicon-o-check" label="Mark as read" wire:click="markRead('{{ $notification->getKey() }}')" />
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="border-t border-gray-200 px-3 py-3 dark:border-gray-700 sm:px-4">
                    {{ $notifications->links() }}
                </div>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white/80 shadow-sm dark:border-gray-700 dark:bg-gray-900/40">
            <div class="border-b border-gray-200 px-4 py-4 sm:px-5 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">My preferences</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Preferences apply only to this business membership.</p>
            </div>
            <form wire:submit="savePreferences" class="space-y-4 p-4 sm:p-5">
                <div class="space-y-2 rounded-xl border border-gray-200 bg-gray-50/60 dark:border-gray-700 dark:bg-gray-950/20">
                    @foreach (\App\Domain\Notifications\NotificationPolicy::TYPES as $type)
                        @php($key = str_replace('.', '_', $type))
                        @php($mandatory = in_array($type, $mandatoryTypes, true))
                        <label class="flex items-center justify-between gap-4 rounded-lg px-3 py-3 sm:px-4 {{ $mandatory ? 'bg-primary-50/40 dark:bg-primary-500/5' : 'bg-transparent' }}">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ str($type)->after('booking.')->replace('_', ' ')->headline() }}</span>
                                @if ($mandatory)
                                    <span class="mt-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">Required</span>
                                @endif
                            </span>
                            <div class="shrink-0">
                                <input type="checkbox" wire:model="preferences.{{ $key }}" @disabled($mandatory) class="h-4 w-4 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 disabled:opacity-60">
                            </div>
                        </label>
                    @endforeach
                </div>
                <x-filament::button type="submit" icon="heroicon-o-check" wire:target="savePreferences" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="savePreferences">Save preferences</span>
                    <span wire:loading wire:target="savePreferences">Saving...</span>
                </x-filament::button>
            </form>
        </section>

        @if (count($settings))
            <section class="rounded-2xl border border-gray-200 bg-white/80 shadow-sm dark:border-gray-700 dark:bg-gray-900/40">
                <div class="border-b border-gray-200 px-4 py-4 sm:px-5 dark:border-gray-700">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Business rules</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Mandatory notifications cannot be disabled.</p>
                </div>
                <form wire:submit="saveSettings" class="space-y-4 p-4 sm:p-5">
                    <div class="space-y-2 rounded-xl border border-gray-200 bg-gray-50/60 dark:border-gray-700 dark:bg-gray-950/20">
                        @foreach ($settings as $index => $setting)
                            <div class="grid gap-3 rounded-lg border border-transparent px-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(12rem,0.8fr)] sm:items-center sm:px-4 {{ $setting['mandatory'] ? 'bg-primary-50/30 dark:bg-primary-500/5' : 'bg-transparent' }}">
                                <div class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ str($setting['notification_type'])->after('booking.')->replace('_', ' ')->headline() }}</span>
                                    <span class="mt-1 inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ str($setting['role'])->headline() }}
                                        @if ($setting['mandatory'])
                                            <span class="inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">Required</span>
                                        @endif
                                    </span>
                                </div>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                    <input type="checkbox" wire:model="settings.{{ $index }}.enabled" @disabled($setting['mandatory']) class="h-4 w-4 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 disabled:opacity-60">
                                    Enabled
                                </label>
                                @if ($setting['notification_type'] === 'booking.reminder_due')
                                    <label class="block text-sm">
                                        <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Reminder intervals (minutes)</span>
                                        <input type="text" wire:model="settings.{{ $index }}.reminder_intervals" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500" placeholder="1440, 120">
                                    </label>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <x-filament::button type="submit" icon="heroicon-o-check" wire:target="saveSettings" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveSettings">Save business rules</span>
                        <span wire:loading wire:target="saveSettings">Saving...</span>
                    </x-filament::button>
                </form>
            </section>
        @endif
    </div>
</x-filament-panels::page>