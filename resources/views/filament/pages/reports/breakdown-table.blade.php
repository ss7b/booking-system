@php
    $dimensions = $section === 'services' ? 'services' : 'staff';
    $statusColumns = ['confirmed', 'checked_in', 'completed', 'cancelled', 'no_show', 'pending'];
@endphp

<x-filament::section :heading="__('reports.'.$dimensions.'.title')">
    @if ($rows->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.'.$dimensions.'.empty') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] divide-y divide-gray-200 text-start dark:divide-white/10">
                <thead>
                    <tr class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        <th scope="col" class="px-3 py-3 text-start">{{ __('reports.breakdown.name') }}</th>
                        <th scope="col" class="px-3 py-3 text-end">{{ __('reports.breakdown.total') }}</th>
                        @foreach ($statusColumns as $status)
                            <th scope="col" class="px-3 py-3 text-end">
                                {{ __($status === 'pending' || $status === 'cancelled'
                                    ? 'reports.statuses.'.$status
                                    : 'bookings.statuses.'.$status) }}
                            </th>
                        @endforeach
                        <th scope="col" class="px-3 py-3 text-end">{{ __('reports.metrics.cancellation_rate') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $row)
                        <tr class="text-sm text-gray-700 dark:text-gray-300">
                            <th scope="row" class="px-3 py-3 text-start font-medium text-gray-950 dark:text-white">
                                {{ $row['id'] === null
                                    ? __('dashboard.staff.staffless')
                                    : ($row['name'] ?? __('reports.breakdown.unnamed')) }}
                            </th>
                            <td class="px-3 py-3 text-end">{{ $row['total'] }}</td>
                            @foreach ($statusColumns as $status)
                                <td class="px-3 py-3 text-end">{{ $row[$status] }}</td>
                            @endforeach
                            <td class="whitespace-nowrap px-3 py-3 text-end">
                                {{ __('dashboard.percentage', ['value' => $row['cancellation_rate']]) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="mt-4">
                <x-filament::pagination :paginator="$rows" />
            </div>
        @endif
    @endif
</x-filament::section>
