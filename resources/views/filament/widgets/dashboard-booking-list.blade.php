@if ($bookings->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $emptyLabel }}</p>
@else
    <ul class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach ($bookings as $booking)
            @php
                $startsAt = $booking->starts_at->setTimezone($booking->location?->timezone ?: ($business->timezone ?: config('app.timezone')));
                $statusColor = match ($booking->status) {
                    \App\Enums\BookingStatus::Held => 'warning',
                    \App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::CheckedIn => 'success',
                    \App\Enums\BookingStatus::Completed => 'gray',
                    default => 'danger',
                };
            @endphp
            <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-2">
                        <p class="break-words text-sm font-medium text-gray-950 dark:text-white">{{ $booking->service_name_snapshot }}</p>
                        @if ($bookingListUrl ?? null)
                            <x-filament::link size="sm" :href="$bookingListUrl . '?tableSearch=' . rawurlencode($booking->reference)">
                                <bdi>{{ $booking->reference }}</bdi>
                            </x-filament::link>
                        @else
                            <bdi class="text-sm text-gray-500 dark:text-gray-400">{{ $booking->reference }}</bdi>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <time datetime="{{ $startsAt->toIso8601String() }}">{{ $startsAt->translatedFormat(__('dashboard_operations.appointment_format')) }}</time>
                        @if ($booking->location)
                            <span class="mx-1" aria-hidden="true">·</span>
                            <bdi>{{ $booking->location->name }}</bdi>
                        @endif
                    </p>
                </div>
                <x-filament::badge :color="$statusColor">{{ __('bookings.statuses.' . $booking->status->value) }}</x-filament::badge>
            </li>
        @endforeach
    </ul>
@endif
