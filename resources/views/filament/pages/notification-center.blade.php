<x-filament-panels::page>
    <div class="space-y-8">
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Inbox</h2>
                    <p class="text-sm text-gray-500">{{ $unreadCount }} unread · {{ $notifications->total() }} notifications</p>
                </div>
                <x-filament::button color="gray" icon="heroicon-o-check" wire:click="markAllRead" wire:target="markAllRead" wire:loading.attr="disabled">
                    Mark all as read
                </x-filament::button>
            </div>

            @if ($notifications->isEmpty())
                <div class="border-y border-gray-200 py-10 text-center text-sm text-gray-500 dark:border-gray-700">
                    No notifications yet.
                </div>
            @else
                <div class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                    @foreach ($notifications as $notification)
                        <article class="flex flex-wrap items-center justify-between gap-4 py-4 {{ $notification->read_at ? 'opacity-70' : '' }}">
                            <div class="min-w-0">
                                <p class="font-medium">{{ str($notification->notification_type)->after('booking.')->replace('_', ' ')->headline() }}</p>
                                @if ($notification->booking)
                                    <p class="text-sm text-gray-500">
                                        {{ $notification->booking->reference }}
                                        <span aria-hidden="true">·</span>
                                        {{ $notification->booking->starts_at->timezone($notification->booking->location?->timezone ?: $business->timezone)->format('M j, Y H:i') }}
                                    </p>
                                @else
                                    <p class="text-sm text-gray-500">The related booking is no longer available.</p>
                                @endif
                                <time class="text-xs text-gray-500" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                            </div>
                            <div class="flex items-center gap-3">
                                @if ($notification->booking_url)
                                    <a class="text-sm font-medium text-primary-600" href="{{ $notification->booking_url }}">Open booking</a>
                                @endif
                                @if (! $notification->read_at)
                                    <x-filament::icon-button icon="heroicon-o-check" label="Mark as read" wire:click="markRead('{{ $notification->getKey() }}')" />
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                {{ $notifications->links() }}
            @endif
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6 dark:border-gray-700">
            <div>
                <h2 class="text-lg font-semibold">My preferences</h2>
                <p class="text-sm text-gray-500">Preferences apply only to this business membership.</p>
            </div>
            <form wire:submit="savePreferences" class="space-y-4">
                <div class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                    @foreach (\App\Domain\Notifications\NotificationPolicy::TYPES as $type)
                        @php($key = str_replace('.', '_', $type))
                        @php($mandatory = in_array($type, $mandatoryTypes, true))
                        <label class="flex items-center justify-between gap-4 py-3">
                            <span>
                                <span class="block font-medium">{{ str($type)->after('booking.')->replace('_', ' ')->headline() }}</span>
                                @if ($mandatory)<span class="text-xs text-gray-500">Required</span>@endif
                            </span>
                            <input type="checkbox" wire:model="preferences.{{ $key }}" @disabled($mandatory) class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 disabled:opacity-60">
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
            <section class="space-y-4 border-t border-gray-200 pt-6 dark:border-gray-700">
                <div>
                    <h2 class="text-lg font-semibold">Business rules</h2>
                    <p class="text-sm text-gray-500">Mandatory notifications cannot be disabled.</p>
                </div>
                <form wire:submit="saveSettings" class="space-y-4">
                    <div class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($settings as $index => $setting)
                            <div class="grid gap-3 py-3 sm:grid-cols-[1fr_auto_minmax(12rem,0.8fr)] sm:items-center">
                                <div>
                                    <span class="block font-medium">{{ str($setting['notification_type'])->after('booking.')->replace('_', ' ')->headline() }}</span>
                                    <span class="text-xs text-gray-500">{{ str($setting['role'])->headline() }}@if ($setting['mandatory']) · Required @endif</span>
                                </div>
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model="settings.{{ $index }}.enabled" @disabled($setting['mandatory']) class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 disabled:opacity-60">
                                    Enabled
                                </label>
                                @if ($setting['notification_type'] === 'booking.reminder_due')
                                    <label class="text-sm">
                                        <span class="sr-only">Reminder intervals in minutes</span>
                                        <input type="text" wire:model="settings.{{ $index }}.reminder_intervals" class="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900" placeholder="1440, 120">
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