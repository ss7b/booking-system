@php
    use Carbon\CarbonImmutable;
    use Filament\Support\Facades\FilamentAsset;

    $chartData = [
        'labels' => array_map(
            fn (string $date): string => CarbonImmutable::parse($date)->locale($locale)->translatedFormat('j M'),
            array_keys($trend),
        ),
        'datasets' => [[
            'label' => __('dashboard.trend.count'),
            'data' => array_values($trend),
            'fill' => true,
            'tension' => 0.25,
            'pointRadius' => count($trend) === 1 ? 5 : 2,
        ]],
    ];
    $rtl = $locale === 'ar';
    $options = [
        'locale' => $locale,
        'maintainAspectRatio' => false,
        'plugins' => [
            'legend' => ['display' => false],
            'tooltip' => ['rtl' => $rtl, 'textDirection' => $rtl ? 'rtl' : 'ltr'],
        ],
        'scales' => [
            'x' => [
                'reverse' => $rtl,
                'title' => ['display' => true, 'text' => __('dashboard.trend.day')],
                'ticks' => ['maxTicksLimit' => 10, 'maxRotation' => 0],
            ],
            'y' => [
                'position' => $rtl ? 'right' : 'left',
                'beginAtZero' => true,
                'title' => ['display' => true, 'text' => __('dashboard.trend.count')],
                'ticks' => ['precision' => 0],
            ],
        ],
    ];
@endphp

<div wire:key="dashboard-trend-{{ md5(serialize([$chartData, $options])) }}">
    <div
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
        wire:ignore
        x-data="chart({ cachedData: @js($chartData), options: @js($options), type: 'line' })"
        class="relative"
        style="height: 18rem"
    >
        <canvas x-ref="canvas" role="img" aria-label="{{ __('dashboard.trend.title') }}"></canvas>
        <span
            x-ref="backgroundColorElement"
            class="text-custom-50 dark:text-custom-400/10"
            style="{{ \Filament\Support\get_color_css_variables('primary', shades: [50, 400]) }}"
        ></span>
        <span x-ref="borderColorElement" class="text-primary-500 dark:text-primary-400"></span>
        <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-800"></span>
        <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
    </div>
</div>
