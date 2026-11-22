<x-filament-widgets::widget>
    <x-filament::grid :default="1" :lg="2" class="gap-6">
        <x-filament::section :heading="__('dashboard_operations.today')" icon="heroicon-o-calendar-days">
            @include('filament.widgets.dashboard-booking-list', [
                'bookings' => $today,
                'emptyLabel' => __('dashboard_operations.today_empty'),
            ])
        </x-filament::section>

        <x-filament::section :heading="__('dashboard_operations.upcoming')" icon="heroicon-o-clock">
            @include('filament.widgets.dashboard-booking-list', [
                'bookings' => $upcoming,
                'emptyLabel' => __('dashboard_operations.upcoming_empty'),
            ])
        </x-filament::section>

        <x-filament::section :heading="__('notifications.title')" icon="heroicon-o-bell">
            @if ($notifications->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('notifications.ui.empty') }}</p>
            @else
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($notifications as $notification)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-950 dark:text-white">
                                    {{ __('notifications.types.' . str_replace('.', '_', $notification->notification_type)) }}
                                </p>
                                <time class="text-xs text-gray-500 dark:text-gray-400" datetime="{{ $notification->created_at->toIso8601String() }}">
                                    {{ $notification->created_at->diffForHumans() }}
                                </time>
                            </div>
                            @unless ($notification->read_at)
                                <x-filament::badge color="primary">{{ __('notifications.ui.unread') }}</x-filament::badge>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        @if ($bookingListUrl || $notificationCenterUrl)
            <x-filament::section :heading="__('dashboard_operations.quick_actions')" icon="heroicon-o-bolt">
                <div class="flex flex-wrap gap-2">
                    @if ($newBookingUrl)
                        <x-filament::button tag="a" :href="$newBookingUrl" icon="heroicon-o-plus">
                            {{ __('actions.new_booking') }}
                        </x-filament::button>
                    @endif

                    @if ($bookingListUrl)
                        <x-filament::button tag="a" :href="$bookingListUrl" color="gray" icon="heroicon-o-calendar-days">
                            {{ __('dashboard_operations.open_bookings') }}
                        </x-filament::button>
                    @endif

                    @if ($notificationCenterUrl)
                        <x-filament::button tag="a" :href="$notificationCenterUrl" color="gray" icon="heroicon-o-bell">
                            {{ __('notifications.open_center') }}
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endif
    </x-filament::grid>
</x-filament-widgets::widget>
