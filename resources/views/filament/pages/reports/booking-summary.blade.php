@php
    use Carbon\CarbonImmutable;
    use Filament\Widgets\StatsOverviewWidget\Stat;

    $locale = app()->getLocale();
    $icons = [
        'total' => 'heroicon-m-calendar-days',
        'confirmed' => 'heroicon-m-check-circle',
        'completed' => 'heroicon-m-clipboard-document-check',
        'cancelled' => 'heroicon-m-x-circle',
        'pending' => 'heroicon-m-clock',
        'cancellation_rate' => 'heroicon-m-chart-bar',
    ];
    $metrics = $overview['statistics'];
@endphp

<x-filament::grid :sm="2" :xl="3" class="gap-6">
    @foreach ($icons as $metric => $icon)
        {{
            Stat::make(
                __('reports.metrics.'.$metric),
                $metric === 'cancellation_rate' ? __('dashboard.percentage', ['value' => $metrics[$metric]]) : $metrics[$metric],
            )
                ->icon($icon)
                ->description(in_array($metric, ['total', 'pending', 'cancellation_rate'], true)
                    ? __('dashboard.statistics.definitions.'.$metric) : null)
                ->extraAttributes(['data-report-stat' => $metric])
        }}
    @endforeach
</x-filament::grid>

<x-filament::grid :xl="2" class="gap-6">
    <x-filament::section :heading="__('reports.bookings.by_day')">
        @if ($metrics['total'] === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.trend.empty') }}</p>
        @else
            <dl class="grid gap-y-3">
                @foreach ($days as $date => $count)
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-gray-500 dark:text-gray-400">
                            <time datetime="{{ $date }}">{{ CarbonImmutable::parse($date)->locale($locale)->translatedFormat('j F Y') }}</time>
                        </dt>
                        <dd class="font-semibold text-gray-950 dark:text-white">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
            @if ($days->hasPages())
                <div class="mt-4">
                    <x-filament::pagination :paginator="$days" />
                </div>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('reports.bookings.by_status')">
        @if ($metrics['total'] + $metrics['pending'] === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.bookings.empty') }}</p>
        @else
            <dl class="grid gap-y-3">
                @foreach ($overview['statuses'] as $status => $count)
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-gray-500 dark:text-gray-400">
                            {{ __($status === 'held' || $status === 'cancelled' ? 'reports.statuses.'.$status : 'bookings.statuses.'.$status) }}
                        </dt>
                        <dd class="font-semibold text-gray-950 dark:text-white">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </x-filament::section>
</x-filament::grid>
