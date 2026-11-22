@php
    $plugin = \Saade\FilamentFullCalendar\FilamentFullCalendarPlugin::get();
@endphp

<x-filament-widgets::widget>
@once
    <style>
        .filament-fullcalendar .fc-event {
            cursor: pointer;
            transition: filter 120ms ease, box-shadow 120ms ease, outline-color 120ms ease;
        }

        .filament-fullcalendar .fc-event:hover,
        .filament-fullcalendar .fc-event:focus-visible {
            filter: brightness(1.08);
            box-shadow: 0 0 0 2px rgb(255 255 255 / 85%), 0 2px 6px rgb(0 0 0 / 18%);
        }

        .filament-fullcalendar .fc-event:focus-visible {
            outline: 2px solid rgb(var(--primary-500));
            outline-offset: 2px;
        }

        .booking-calendar-tooltip {
            position: fixed;
            z-index: 9999;
            box-sizing: border-box;
            max-width: min(24rem, calc(100vw - 1rem));
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            background: #fff;
            color: #111827;
            box-shadow: 0 4px 12px rgb(0 0 0 / 18%);
            font-size: 0.875rem;
            line-height: 1.25rem;
            overflow-wrap: anywhere;
            pointer-events: none;
            text-align: start;
        }

        .booking-calendar-tooltip[hidden] {
            display: none;
        }

        html.dark .booking-calendar-tooltip {
            border-color: #4b5563;
            background: #111827;
            color: #f9fafb;
        }

        @media (prefers-reduced-motion: reduce) {
            .filament-fullcalendar .fc-event {
                transition: none;
            }
        }
    </style>
@endonce

    <x-filament::section>
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
            {{ __('bookings.calendar.timezone_notice', ['timezone' => $this->timezoneLabel()]) }}
        </p>

        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <label class="text-sm font-medium">
                <span>{{ __('bookings.calendar.location') }}</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select wire:model.live="filters.location_id">
                        <option value="">{{ __('bookings.calendar.all_locations') }}</option>
                        @foreach ($this->locationOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <label class="text-sm font-medium">
                <span>{{ __('bookings.calendar.service') }}</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select wire:model.live="filters.service_id">
                        <option value="">{{ __('bookings.calendar.all_services') }}</option>
                        @foreach ($this->serviceOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <label class="text-sm font-medium">
                <span>{{ __('bookings.calendar.staff') }}</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select wire:model.live="filters.staff_id">
                        <option value="">{{ __('bookings.calendar.all_staff') }}</option>
                        <option value="none">{{ __('bookings.calendar.staffless_only') }}</option>
                        @foreach ($this->staffOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <label class="text-sm font-medium">
                <span>{{ __('bookings.calendar.status') }}</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select wire:model.live="filters.status">
                        <option value="">{{ __('bookings.calendar.all_statuses') }}</option>
                        @foreach ($this->statusOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </div>

        <div class="filament-fullcalendar" wire:ignore x-load
            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('filament-fullcalendar-alpine', 'saade/filament-fullcalendar') }}"
            ax-load-css="{{ \Filament\Support\Facades\FilamentAsset::getStyleHref('filament-fullcalendar-styles', 'saade/filament-fullcalendar') }}"
            x-ignore x-data="fullcalendar({
                locale: @js(app()->getLocale()),
                plugins: @js($plugin->getPlugins()),
                timeZone: @js($plugin->getTimezone()),
                config: @js($this->getConfig()),
                editable: false,
                selectable: false,
                eventClassNames: {!! htmlspecialchars($this->eventClassNames(), ENT_COMPAT) !!},
                eventContent: {!! htmlspecialchars($this->eventContent(), ENT_COMPAT) !!},
                eventDidMount: {!! htmlspecialchars($this->eventDidMount(), ENT_COMPAT) !!},
                eventWillUnmount: {!! htmlspecialchars($this->eventWillUnmount(), ENT_COMPAT) !!},
            })">
        </div>
    </x-filament::section>

    <x-filament-actions::modals />

    @script
        <script>
            const componentId = $wire.__instance.id;

            window.addEventListener('open-modal', (event) => {
                if (event.detail?.id !== `${componentId}-action`) {
                    return;
                }

                requestAnimationFrame(() => {
                    const modal = document.querySelector(`[data-fi-modal-id="${componentId}-action"]`);
                    const modalWindow = modal?.querySelector('.fi-modal-window');

                    if (! modal || ! modalWindow) {
                        return;
                    }

                    Alpine.$data(modal).open();
                    Alpine.$data(modalWindow).isShown = true;
                });
            });
        </script>
    @endscript
</x-filament-widgets::widget>
