@php
    use App\Domain\Reports\ReportPeriod;
    use Filament\Widgets\StatsOverviewWidget\Stat;

    $locale = app()->getLocale();
    $statIcons = [
        'total' => 'heroicon-m-calendar-days',
        'confirmed' => 'heroicon-m-check-circle',
        'completed' => 'heroicon-m-clipboard-document-check',
        'cancelled' => 'heroicon-m-x-circle',
        'pending' => 'heroicon-m-clock',
        'cancellation_rate' => 'heroicon-m-chart-bar',
    ];
    $start = $report['start']->locale($locale);
    $lastDay = $report['end']->subDay()->locale($locale);
    $range = $reportPeriod === null
        ? __('reports.filters.date_range', [
            'start' => $start->translatedFormat('j F Y'),
            'end' => $lastDay->translatedFormat('j F Y'),
        ])
        : match ($reportPeriod) {
            ReportPeriod::Today => $start->translatedFormat('j F Y'),
            ReportPeriod::CurrentWeek => __('dashboard.filters.date_range', [
                'first' => $start->translatedFormat('j F Y'),
                'last' => $lastDay->translatedFormat('j F Y'),
            ]),
            ReportPeriod::CurrentMonth => $start->translatedFormat('F Y'),
        };
    $describedStats = ['total', 'cancelled', 'pending', 'cancellation_rate'];
@endphp

<div class="grid gap-6">
    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
        {{ __('dashboard.filters.applied_scope', ['branch' => $branchName, 'range' => $range]) }}
    </p>

    <x-filament::grid :sm="2" :xl="3" class="gap-6">
        @foreach ($statIcons as $metric => $icon)
            {{
                Stat::make(
                    __('dashboard.statistics.'.$metric),
                    $metric === 'cancellation_rate'
                        ? __('dashboard.percentage', ['value' => $report['statistics'][$metric]])
                        : $report['statistics'][$metric],
                )
                    ->icon($icon)
                    ->description(in_array($metric, $describedStats, true) ? __('dashboard.statistics.definitions.'.$metric) : null)
                    ->extraAttributes(['data-dashboard-stat' => $metric])
            }}
        @endforeach
    </x-filament::grid>

    <x-filament::section :heading="__('dashboard.trend.title')">
        @if ($report['statistics']['total'] > 0)
            @include('filament.widgets.dashboard-trend-chart', ['trend' => $report['trend'], 'locale' => $locale])
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.trend.empty') }}</p>
        @endif
    </x-filament::section>

    <x-filament::grid :xl="2" class="gap-6">
        <x-filament::section :heading="__('dashboard.services.title')">
            @include('filament.widgets.dashboard-booking-distribution', [
                'rows' => $report['services'],
                'emptyMessage' => __('dashboard.services.empty'),
                'unnamedLabel' => __('dashboard.services.unnamed'),
            ])
        </x-filament::section>

        <x-filament::section :heading="__('dashboard.staff.title')">
            @include('filament.widgets.dashboard-booking-distribution', [
                'rows' => $report['staff'],
                'emptyMessage' => __('dashboard.staff.empty'),
                'unnamedLabel' => __('dashboard.staff.unnamed'),
                'unassignedLabel' => __('dashboard.staff.staffless'),
            ])
        </x-filament::section>
    </x-filament::grid>

    <x-filament::section :heading="__('dashboard.upcoming')">
        @include('filament.widgets.dashboard-booking-list', [
            'bookings' => $report['upcoming'],
            'business' => $business,
            'emptyLabel' => __('dashboard_operations.upcoming_empty'),
        ])
    </x-filament::section>
</div>
