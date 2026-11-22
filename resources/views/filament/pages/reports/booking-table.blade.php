<x-filament::section :heading="__('reports.bookings.details')">
    <div class="grid gap-4">
        <form wire:submit="searchBookings" class="flex flex-col gap-3 sm:flex-row">
            <x-filament::input.wrapper class="min-w-0 flex-1">
                <x-filament::input
                    type="search"
                    wire:model="bookingSearch"
                    :placeholder="__('reports.bookings.search_placeholder')"
                    :aria-label="__('reports.bookings.search_placeholder')"
                    maxlength="150"
                />
            </x-filament::input.wrapper>
            <x-filament::button type="submit" wire:target="searchBookings">
                {{ __('reports.bookings.search') }}
            </x-filament::button>
        </form>

        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.bookings.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[60rem] divide-y divide-gray-200 text-start dark:divide-white/10">
                    <thead>
                        <tr class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            @foreach ($bookingColumns as $column)
                                <th scope="col" class="px-3 py-3 text-start">
                                    <button
                                        type="button"
                                        wire:click="sortBookings('{{ $column }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="sortBookings"
                                        class="font-medium hover:text-gray-950 dark:hover:text-white"
                                    >
                                        {{ __('reports.bookings.columns.'.$column) }}
                                        @if ($bookingSort === $column)
                                            <span aria-hidden="true">{{ $bookingDirection === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $booking)
                            @php
                                $startsAt = $booking->starts_at->setTimezone($booking->location_timezone);
                                $recordUrl = $bookingListUrl === null
                                    ? null
                                    : \App\Filament\Resources\BookingResource::getUrl('index', [
                                        'tenant' => \Filament\Facades\Filament::getTenant(),
                                        'tableSearch' => $booking->reference,
                                    ]);
                            @endphp
                            <tr class="text-sm text-gray-700 dark:text-gray-300">
                                <td class="whitespace-nowrap px-3 py-3 font-medium text-gray-950 dark:text-white">
                                    @if ($recordUrl !== null)
                                        <a href="{{ $recordUrl }}" class="text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $booking->reference }}
                                        </a>
                                    @else
                                        {{ $booking->reference }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <time datetime="{{ $booking->starts_at->toIso8601String() }}">
                                        {{ $startsAt->locale(app()->getLocale())->translatedFormat('j M Y, H:i') }}
                                    </time>
                                </td>
                                <td class="px-3 py-3">{{ $booking->service_name_snapshot }}</td>
                                <td class="px-3 py-3">{{ $booking->staff_name ?? __('dashboard.staff.staffless') }}</td>
                                <td class="px-3 py-3">{{ $booking->customer_name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3">
                                    {{ __($booking->status->value === 'held' || $booking->status->value === 'cancelled'
                                        ? 'reports.statuses.'.$booking->status->value
                                        : 'bookings.statuses.'.$booking->status->value) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($rows->hasPages())
                <x-filament::pagination :paginator="$rows" />
            @endif
        @endif
    </div>
</x-filament::section>
