@php
    use Carbon\CarbonImmutable;

    $locale = app()->getLocale();
    $window = $comparison['window'];
    $metrics = $comparison['metrics'];
    $periodName = fn (CarbonImmutable $date): string => $date
        ->locale($locale)
        ->translatedFormat('j M Y H:i:s T');
    $currentPeriodName = $period === 'custom'
        ? __('reports.comparison.custom_period')
        : __('dashboard.filters.'.$period);
    $previousPeriodName = $period === 'custom'
        ? __('reports.comparison.previous_equal_period')
        : __('reports.comparison.previous_period');
    $metricLabels = [
        'total' => __('reports.metrics.total'),
        'confirmed' => __('reports.metrics.confirmed'),
        'completed' => __('reports.metrics.completed'),
        'cancelled' => __('reports.metrics.cancelled'),
        'cancellation_rate' => __('reports.metrics.cancellation_rate'),
    ];
    $allTrendValues = $comparison['trend'] === null
        ? []
        : [...$comparison['trend']['current'], ...$comparison['trend']['previous']];
    $maxTrendValue = max($allTrendValues ?: [0]);
    $maxTrendValue = max(1, $maxTrendValue);
    $decimalLabel = static fn (float|int $value): string => ($value > 0 ? '+' : '').number_format($value, 2);
    $countChangeLabel = static fn (int $value): string => ($value > 0 ? '+' : '').number_format($value);
@endphp

<div class="grid min-w-0 gap-6">
    <x-filament::section :heading="__('reports.comparison.periods_heading')">
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ([
                ['name' => $currentPeriodName, 'start' => $window->currentStart, 'end' => $window->currentEnd],
                ['name' => $previousPeriodName, 'start' => $window->previousStart, 'end' => $window->previousEnd],
            ] as $range)
                <div class="min-w-0 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    <p class="font-semibold text-gray-950 dark:text-white">{{ $range['name'] }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('reports.comparison.branch', ['branch' => $branchName]) }}
                    </p>
                    <p class="mt-2 break-words text-sm text-gray-700 dark:text-gray-300">
                        <time datetime="{{ $range['start']->toIso8601String() }}">{{ $periodName($range['start']) }}</time>
                        <span aria-hidden="true">→</span>
                        <time datetime="{{ $range['end']->toIso8601String() }}">{{ $periodName($range['end']) }}</time>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('reports.comparison.end_exclusive') }}
                        </span>
                    </p>
                </div>
            @endforeach
        </div>

        @if ($window->currentPartial)
            <p role="note" class="mt-4 rounded-lg border border-primary-200 p-3 text-sm text-primary-800 dark:border-primary-400/30 dark:text-primary-200">
                {{ __('reports.comparison.current_partial') }}
            </p>
        @endif

        @if (! $window->comparable)
            <p role="note" class="mt-3 rounded-lg border border-warning-300 p-3 text-sm text-warning-800 dark:border-warning-400/30 dark:text-warning-200">
                {{ $window->previousCapped
                    ? __('reports.comparison.previous_capped')
                    : __('reports.comparison.unequal_periods') }}
            </p>
        @elseif ($window->custom)
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                {{ __('reports.comparison.equal_calendar_days', ['days' => $window->currentDays]) }}
            </p>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('reports.comparison.metrics_heading')">
        <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-3">
            @foreach ($metrics as $metric => $values)
                @php
                    $isRate = $metric === 'cancellation_rate';
                    $direction = $values['difference'] <=> 0;
                    $icon = $direction > 0
                        ? 'heroicon-m-arrow-trending-up'
                        : ($direction < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-minus');
                    $color = $isRate
                        ? ($direction > 0 ? 'text-danger-600 dark:text-danger-400' : ($direction < 0 ? 'text-success-600 dark:text-success-400' : 'text-gray-500 dark:text-gray-400'))
                        : 'text-primary-600 dark:text-primary-400';
                    $directionText = $direction === 0
                        ? __('reports.comparison.no_change')
                        : ($isRate
                            ? ($direction > 0 ? __('reports.comparison.cancellations_increased') : __('reports.comparison.cancellations_decreased'))
                            : ($direction > 0 ? __('reports.comparison.increased') : __('reports.comparison.decreased')));
                    $currentValue = $isRate
                        ? __('dashboard.percentage', ['value' => $values['current']])
                        : number_format($values['current']);
                    $previousValue = $isRate
                        ? __('dashboard.percentage', ['value' => $values['previous']])
                        : number_format($values['previous']);
                @endphp

                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $metricLabels[$metric] }}</h3>
                    <p class="mt-3 text-2xl font-semibold text-gray-950 dark:text-white">{{ $currentValue }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('reports.comparison.previous_value', ['value' => $previousValue]) }}
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm {{ $color }}">
                        <x-filament::icon :icon="$icon" class="h-4 w-4" aria-hidden="true" />
                        <span>{{ $directionText }}</span>
                        @if ($isRate)
                            <span>{{ $decimalLabel($values['difference']) }} {{ __('reports.comparison.percentage_points') }}</span>
                        @else
                            <span>{{ __('reports.comparison.difference', ['value' => $countChangeLabel($values['difference'])]) }}</span>
                            @if ($values['change_state'] === 'no_previous')
                                <span>{{ __('reports.comparison.no_previous') }}</span>
                            @elseif ($values['change_state'] === 'both_zero')
                                <span>{{ __('reports.comparison.both_zero') }}</span>
                            @else
                                <span>{{ __('reports.comparison.change_percent', ['value' => $decimalLabel($values['change_percent'])]) }}</span>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            {{ __('reports.comparison.active_held', [
                'current' => $comparison['active_held']['current'],
                'previous' => $comparison['active_held']['previous'],
            ]) }}
        </p>
    </x-filament::section>

    <x-filament::section :heading="__('reports.comparison.trend_heading')">
        @if ($comparison['trend'] === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $window->currentDays > 366 || $window->previousDays > 366
                    ? __('reports.comparison.trend_too_long')
                    : __('reports.comparison.trend_unavailable') }}
            </p>
        @elseif (array_sum($allTrendValues) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.comparison.trend_empty') }}</p>
        @else
            <div class="mb-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-600 dark:text-gray-300">
                <span class="inline-flex items-center gap-2">
                    <span class="h-3 w-3 rounded-sm bg-primary-500"></span>
                    {{ __('reports.comparison.current_series') }}
                </span>
                <span class="inline-flex items-center gap-2">
                    <span class="h-3 w-3 rounded-sm bg-gray-400 dark:bg-gray-500"></span>
                    {{ __('reports.comparison.previous_series') }}
                </span>
            </div>
            <div
                role="img"
                aria-label="{{ __('reports.comparison.trend_accessible_label') }}"
                class="overflow-x-auto"
            >
                <div class="grid min-w-[42rem] gap-2" style="grid-template-columns: repeat({{ count($comparison['trend']['labels']) }}, minmax(1.5rem, 1fr))">
                    @foreach ($comparison['trend']['labels'] as $index => $day)
                        @php
                            $currentCount = $comparison['trend']['current'][$index];
                            $previousCount = $comparison['trend']['previous'][$index];
                            $currentHeight = $currentCount === 0 ? 0 : max(8, 100 * $currentCount / $maxTrendValue);
                            $previousHeight = $previousCount === 0 ? 0 : max(8, 100 * $previousCount / $maxTrendValue);
                        @endphp
                        <div class="grid min-w-0 gap-2 text-center">
                            <div class="flex h-40 items-end justify-center gap-1 border-b border-gray-200 dark:border-white/10">
                                <span
                                    class="w-2/5 rounded-t bg-primary-500"
                                    style="height: {{ $currentHeight }}%"
                                    title="{{ __('reports.comparison.current_point', ['day' => $day, 'count' => $currentCount]) }}"
                                ></span>
                                <span
                                    class="w-2/5 rounded-t bg-gray-400 dark:bg-gray-500"
                                    style="height: {{ $previousHeight }}%"
                                    title="{{ __('reports.comparison.previous_point', ['day' => $day, 'count' => $previousCount]) }}"
                                ></span>
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('reports.comparison.day_number', ['day' => $day]) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('reports.comparison.services_heading')">
        @if ($comparison['services'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.comparison.services_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[38rem] divide-y divide-gray-200 text-start dark:divide-white/10">
                    <thead>
                        <tr class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            <th scope="col" class="px-3 py-3 text-start">{{ __('reports.breakdown.name') }}</th>
                            <th scope="col" class="px-3 py-3 text-end">{{ __('reports.comparison.current') }}</th>
                            <th scope="col" class="px-3 py-3 text-end">{{ __('reports.comparison.previous') }}</th>
                            <th scope="col" class="px-3 py-3 text-end">{{ __('reports.comparison.difference_label') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($comparison['services'] as $service)
                            <tr class="text-sm text-gray-700 dark:text-gray-300">
                                <th scope="row" class="px-3 py-3 text-start font-medium text-gray-950 dark:text-white">
                                    {{ $service['name'] !== '' ? $service['name'] : __('reports.breakdown.unnamed') }}
                                </th>
                                <td class="px-3 py-3 text-end">{{ $service['current'] }}</td>
                                <td class="px-3 py-3 text-end">{{ $service['previous'] }}</td>
                                <td class="px-3 py-3 text-end">{{ $countChangeLabel($service['difference']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</div>
