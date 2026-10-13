<div wire:poll.5s.visible="poll" class="flex items-center">
    <a
        href="{{ $this->notificationCenterUrl() }}"
        aria-label="Notifications{{ $unreadCount > 0 ? ', '.$unreadCount.' unread' : '' }}"
        title="Notifications"
        class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-gray-600 transition hover:bg-gray-100 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white"
    >
        <x-filament::icon icon="heroicon-o-bell" class="h-8 w-8" />
        @if ($unreadCount > 0)
            <span class="absolute end-6 -top-2 inline-flex min-w-4 items-center justify-center rounded-full bg-primary-600 px-0.5 py-0.3 text-[4px] font-semibold leading-none text-white">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
        <span class="sr-only">Open notification center</span>
    </a>
</div>