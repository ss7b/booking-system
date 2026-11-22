@php
    use App\Domain\Availability\Services\AvailabilityService;
    use App\Models\Location;
    use App\Models\Service;
    use App\Models\ServiceLocation;
    use App\Models\Staff;
    use Carbon\CarbonImmutable;

    $get = $field->getGetCallback();

    $state = $field->getState();
    $locationId = $get('location_id');
    $serviceId = $get('service_id');
    $staffId = $get('staff_id');
    $statePath = $field->getStatePath();
    $selectedDatePath = $field->getContainer()
        ->getComponent(fn ($component): bool => $component->getStatePath(false) === 'selected_date', withHidden: true)
        ?->getStatePath();
    $calendarWeekStartPath = $field->getContainer()
        ->getComponent(fn ($component): bool => $component->getStatePath(false) === 'calendar_week_start', withHidden: true)
        ?->getStatePath();
    $business = Filament\Facades\Filament::getTenant();
    $timezone = $business?->timezone ?? config('app.timezone');

    $location = null;
    if ($business && $locationId) {
        $location = Location::query()
            ->where('business_id', $business->getKey())
            ->whereKey($locationId)
            ->first();
        $timezone = $location?->timezone ?: $timezone;
    }

    $selectedDate = $get('selected_date');
    $selectedStart = null;

    if (filled($state)) {
        try {
            $selectedDate ??= CarbonImmutable::parse($state, $timezone)->setTimezone($timezone)->format('Y-m-d');
            $selectedStart = CarbonImmutable::parse($state, $timezone)->setTimezone($timezone)->format('c');
        } catch (\Throwable $exception) {
            $selectedStart = null;
        }
    }

    $calendarWeekStart = filled($get('calendar_week_start'))
        ? CarbonImmutable::parse($get('calendar_week_start'), $timezone)->startOfWeek(0)
        : ($selectedDate
            ? CarbonImmutable::parse($selectedDate, $timezone)->startOfWeek(0)
            : CarbonImmutable::now($timezone)->startOfWeek(0));
    $startOfGrid = $calendarWeekStart;
    $endOfGrid = $startOfGrid->addDays(6);
    $previousWeekStart = $startOfGrid->subWeek();
    $nextWeekStart = $startOfGrid->addWeek();
    $monthSlots = [];
    $calendarError = null;
    $selectedDaySlots = [];

    if ($business && $location && $serviceId) {
        try {
            $service = Service::query()
                ->where('business_id', $business->getKey())
                ->whereKey($serviceId)
                ->first();
            $staff = $staffId
                ? Staff::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($staffId)
                    ->first()
                : null;

            if ($location && $service) {
                if ($staffId && ! $staff) {
                    $calendarError = __('bookings.picker.staff_unavailable');
                } else {
                    $assignment = ServiceLocation::query()
                        ->where('service_id', $serviceId)
                        ->where('location_id', $locationId)
                        ->first();

                    $dateCursor = $startOfGrid->copy();
                    while ($dateCursor->lte($endOfGrid)) {
                        $dateKey = $dateCursor->format('Y-m-d');

                        if (! $dateCursor->startOfDay()->isBefore(CarbonImmutable::now($timezone)->startOfDay())) {
                            $result = app(AvailabilityService::class)->forDate(
                                $business,
                                $location,
                                $service,
                                $dateKey,
                                $staff,
                                assignment: $assignment,
                            );
                            $monthSlots[$dateKey] = array_values($result['slots'] ?? []);
                        }

                        $dateCursor = $dateCursor->addDay();
                    }

                    if ($selectedDate) {
                        $selectedDaySlots = $monthSlots[$selectedDate] ?? [];
                    }
                }
            }
        } catch (\Throwable $exception) {
            $calendarError = __('bookings.picker.load_error');
        }
    }

    $morningLabel = __('bookings.picker.morning');
    $eveningLabel = __('bookings.picker.evening');
    $slotGroups = [$morningLabel => [], $eveningLabel => []];
    foreach ($selectedDaySlots as $slot) {
        $slotStart = CarbonImmutable::parse($slot['start'], $timezone);
        $slotLabel = $slotStart->setTimezone($timezone)->translatedFormat('g:i A');
        $periodLabel = $slotStart->hour < 12 ? $morningLabel : $eveningLabel;

        $slotGroups[$periodLabel][] = ['start' => $slot['start'], 'label' => $slotLabel];
    }

    $weekdays = collect(range(0, 6))
        ->map(fn (int $offset): string => CarbonImmutable::now($timezone)->startOfWeek(0)->addDays($offset)->translatedFormat('D'))
        ->all();
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div class="space-y-4">
        @if (! $locationId || ! $serviceId)
            <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-3 py-4 text-sm text-gray-600">
                {{ __('bookings.picker.select_location_service') }}
            </div>
        @else
            @if ($calendarError)
                <div class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ $calendarError }}
                </div>
            @endif

            <div class="rounded-2xl border border-gray-200 bg-white p-3 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <button
                        type="button"
                        x-on:click="$wire.set('{{ $calendarWeekStartPath }}', '{{ $previousWeekStart->format('Y-m-d') }}'); $wire.set('{{ $selectedDatePath }}', null); $wire.set('{{ $statePath }}', null)"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700"
                        @disabled($previousWeekStart->addDays(6)->endOfDay()->isBefore(CarbonImmutable::now($timezone)))
                    >
                        {{ __('bookings.picker.previous_week') }}
                    </button>
                    <div class="text-base font-semibold text-gray-900">
                        {{ $startOfGrid->translatedFormat('M j') }} {{ __('bookings.picker.date_range_separator') }} {{ $endOfGrid->translatedFormat('M j, Y') }}
                    </div>
                    <button
                        type="button"
                        x-on:click="$wire.set('{{ $calendarWeekStartPath }}', '{{ $nextWeekStart->format('Y-m-d') }}'); $wire.set('{{ $selectedDatePath }}', null); $wire.set('{{ $statePath }}', null)"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700"
                    >
                        {{ __('bookings.picker.next_week') }}
                    </button>
                </div>

                <div class="grid grid-cols-7 gap-2 text-center text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                    @foreach ($weekdays as $weekday)
                        <div>{{ $weekday }}</div>
                    @endforeach
                </div>

                <div class="mt-2 grid grid-cols-7 gap-2">
                    @foreach (range(0, (int) $startOfGrid->diffInDays($endOfGrid)) as $offset)
                        @php
                            $day = $startOfGrid->copy()->addDays($offset);
                            $dayKey = $day->format('Y-m-d');
                            $isPast = $day->startOfDay()->isBefore(CarbonImmutable::now($timezone)->startOfDay());
                            $isSelected = $selectedDate === $dayKey;
                            $availableSlots = $monthSlots[$dayKey] ?? [];
                            $isAvailable = $availableSlots !== [];
                        @endphp

                        <button
                            type="button"
                            @if (! $isPast && $isAvailable)
                                x-on:click="$wire.set('{{ $selectedDatePath }}', '{{ $dayKey }}'); $wire.set('{{ $statePath }}', null)"
                            @endif
                            class="flex min-h-12 flex-col items-center justify-center rounded-xl border text-xs transition
                                @if($isSelected) border-primary-500 bg-primary-500/10 text-primary-600 ring-2 ring-primary-200
                                @elseif($isAvailable && ! $isPast) border-green-200 bg-green-50 text-green-700
                                @elseif(! $isPast) border-red-200 bg-red-50 text-red-600
                                @else border-gray-200 bg-gray-50 text-gray-400 opacity-60
                                @endif"
                            @disabled($isPast || ! $isAvailable)
                        >
                            <span class="font-semibold">{{ $day->translatedFormat('D j') }}</span>
                            @if ($isAvailable && ! $isPast)
                                <span class="text-[10px]">{{ __('bookings.picker.open') }}</span>
                            @elseif (! $isAvailable && ! $isPast)
                                <span class="text-[10px]">{{ __('bookings.picker.closed') }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <div class="mt-4 flex flex-wrap gap-3 text-xs text-gray-600">
                    <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-green-500"></span>{{ __('bookings.picker.available') }}</span>
                    <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-red-500"></span>{{ __('bookings.picker.unavailable') }}</span>
                    <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-primary-500"></span>{{ __('bookings.picker.selected') }}</span>
                </div>
            </div>

            @if ($selectedDate)
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-3">
                    <div class="mb-3 text-sm font-semibold text-gray-900">
                        {{ CarbonImmutable::parse($selectedDate, $timezone)->translatedFormat('l, F j') }}
                    </div>

                    @foreach ($slotGroups as $label => $slots)
                        <div class="mb-3">
                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</div>

                            @if ($slots === [])
                                <div class="text-sm text-gray-500">{{ __('bookings.picker.no_slots', ['period' => $label]) }}</div>
                            @else
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($slots as $slot)
                                        <button
                                            type="button"
                                            x-on:click="$wire.set('{{ $statePath }}', '{{ $slot['start'] }}')"
                                            class="rounded-lg border px-3 py-2 text-sm font-medium transition
                                                @if ($selectedStart === $slot['start']) border-primary-500 bg-primary-500 text-white
                                                @else border-gray-300 bg-white text-gray-700 hover:border-primary-400 hover:text-primary-700
                                                @endif"
                                        >
                                            {{ $slot['label'] }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endif

    </div>
</x-dynamic-component>
