<?php

namespace App\Filament\Widgets;

use App\Domain\Authorization\BusinessAccess;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource\BookingManagementActions;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Saade\FilamentFullCalendar\Actions\ViewAction as FullCalendarViewAction;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

class BookingCalendarWidget extends FullCalendarWidget
{
    protected static string $view = 'filament.widgets.booking-calendar';

    public array $filters = [
        'location_id' => null,
        'service_id' => null,
        'staff_id' => null,
        'status' => null,
    ];

    public function mount(): void
    {
        $this->model = Booking::class;
    }

    public function updatedFilters(): void
    {
        $this->refreshRecords();
    }

    public function onEventClick(array $event): void
    {
        $key = $event['id'] ?? null;
        abort_unless(is_string($key) || is_int($key), 404);

        $booking = $this->resolveRecord($key);
        Gate::authorize('view', $booking);
        $this->record = $booking;

        $this->mountAction('view', [
            'type' => 'click',
            'event' => ['id' => (string) $booking->getKey()],
        ]);
    }

    public function refreshAfterAction(): void
    {
        if ($record = $this->getRecord()) {
            $this->record = $this->getEloquentQuery()->find($record->getKey());
        }

        $this->refreshRecords();
    }

    public function fetchEvents(array $info): array
    {
        if (! isset($info['start'], $info['end']) || ! is_string($info['start']) || ! is_string($info['end'])) {
            throw new InvalidArgumentException('A valid calendar date range is required.');
        }

        $start = CarbonImmutable::parse($info['start'])->utc();
        $end = CarbonImmutable::parse($info['end'])->utc();

        if ($end->lessThanOrEqualTo($start) || $start->diffInDays($end) > 93) {
            throw new InvalidArgumentException('The calendar date range is invalid or too large.');
        }

        $query = $this->getEloquentQuery()
            ->with(['location:id,name', 'staff:id,name'])
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at');

        if ($locationId = $this->selectedFilter('location_id')) {
            $query->where('location_id', $locationId);
        }

        if ($serviceId = $this->selectedFilter('service_id')) {
            $query->where('service_id', $serviceId);
        }

        if (($staffId = $this->selectedFilter('staff_id')) === 'none') {
            $query->whereNull('staff_id');
        } elseif ($staffId) {
            $query->where('staff_id', $staffId);
        }

        if ($status = $this->selectedFilter('status')) {
            $bookingStatus = BookingStatus::tryFrom($status);

            if (! $bookingStatus) {
                throw new InvalidArgumentException('The selected booking status is invalid.');
            }

            $query->where('status', $bookingStatus->value);
        }

        return $query->get()->map(fn (Booking $booking): array => [
            'id' => (string) $booking->getKey(),
            'title' => implode(' - ', array_filter([
                $booking->service_name_snapshot,
                $booking->location?->name,
                $booking->staff?->name ?? 'Staffless',
            ])),
            'start' => $booking->starts_at->toIso8601String(),
            'end' => $booking->ends_at->toIso8601String(),
            'backgroundColor' => $this->statusColor($booking->status),
            'borderColor' => $this->statusColor($booking->status),
            'textColor' => '#FFFFFF',
            'extendedProps' => [
                'status' => $booking->status->value,
                'staffless' => $booking->staff_id === null,
                'serviceName' => $booking->service_name_snapshot,
            ],
        ])->all();
    }

    public function locationOptions(): array
    {
        [$user, $business] = $this->authorizedContext();

        return app(BusinessAccess::class)->locations($user, $business)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function serviceOptions(): array
    {
        return $this->getEloquentQuery()
            ->select('service_id', 'service_name_snapshot')
            ->distinct()
            ->orderBy('service_name_snapshot')
            ->pluck('service_name_snapshot', 'service_id')
            ->all();
    }

    public function staffOptions(): array
    {
        return Staff::query()
            ->whereIn('id', $this->getEloquentQuery()->whereNotNull('staff_id')->select('staff_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function statusOptions(): array
    {
        return collect(BookingStatus::cases())
            ->mapWithKeys(fn (BookingStatus $status): array => [
                $status->value => str($status->value)->headline()->toString(),
            ])
            ->all();
    }

    public function timezoneLabel(): string
    {
        return $this->businessTimezone();
    }

    public function config(): array
    {
        return [
            'timeZone' => $this->businessTimezone(),
            'initialView' => 'dayGridMonth',
            'headerToolbar' => [
                'left' => 'prev,next today',
                'center' => 'title',
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
            ],
            'editable' => false,
            'selectable' => false,
            'nowIndicator' => true,
            'height' => 'auto',
        ];
    }

    public function eventDidMount(): string
    {
        return <<<'JS'
            (info) => {
                const serviceName = String(info.event.extendedProps.serviceName ?? '');

                if (!serviceName) return;

                const tooltip = document.createElement('div');
                tooltip.className = 'booking-calendar-tooltip';
                tooltip.id = `booking-calendar-tooltip-${Math.random().toString(36).slice(2)}`;
                tooltip.textContent = serviceName;
                tooltip.setAttribute('role', 'tooltip');
                tooltip.setAttribute('dir', 'auto');
                tooltip.setAttribute('aria-hidden', 'true');
                tooltip.hidden = true;
                document.body.append(tooltip);

                const eventElement = info.el;
                eventElement.setAttribute('aria-label', serviceName);
                eventElement.setAttribute('aria-describedby', tooltip.id);
                eventElement.setAttribute('tabindex', '0');

                const position = () => {
                    if (tooltip.hidden) return;

                    const rect = eventElement.getBoundingClientRect();
                    const tooltipRect = tooltip.getBoundingClientRect();
                    const margin = 8;
                    const maxLeft = Math.max(margin, window.innerWidth - tooltipRect.width - margin);
                    let left = Math.min(Math.max(rect.left, margin), maxLeft);
                    let top = rect.bottom + margin;

                    if (top + tooltipRect.height > window.innerHeight - margin) {
                        top = rect.top - tooltipRect.height - margin;
                    }

                    top = Math.min(Math.max(top, margin), Math.max(margin, window.innerHeight - tooltipRect.height - margin));
                    tooltip.style.left = `${left}px`;
                    tooltip.style.top = `${top}px`;
                };

                const show = () => {
                    tooltip.hidden = false;
                    tooltip.setAttribute('aria-hidden', 'false');
                    position();
                };

                const hide = () => {
                    tooltip.hidden = true;
                    tooltip.setAttribute('aria-hidden', 'true');
                };

                eventElement.addEventListener('mouseenter', show);
                eventElement.addEventListener('mouseleave', hide);
                eventElement.addEventListener('focus', show);
                eventElement.addEventListener('blur', hide);
                window.addEventListener('resize', position);
                window.addEventListener('scroll', position, true);

                eventElement.__bookingCalendarTooltip = { tooltip, show, hide, position };
            }
        JS;
    }

    public function eventWillUnmount(): string
    {
        return <<<'JS'
            (info) => {
                const binding = info.el.__bookingCalendarTooltip;

                if (!binding) return;

                info.el.removeEventListener('mouseenter', binding.show);
                info.el.removeEventListener('mouseleave', binding.hide);
                info.el.removeEventListener('focus', binding.show);
                info.el.removeEventListener('blur', binding.hide);
                window.removeEventListener('resize', binding.position);
                window.removeEventListener('scroll', binding.position, true);
                binding.tooltip.remove();
                delete info.el.__bookingCalendarTooltip;
            }
        JS;
    }

    public function onDateSelect(string $start, ?string $end, bool $allDay, ?array $view, ?array $resource): void
    {
        abort(403);
    }

    public function onEventDrop(array $event, array $oldEvent, array $relatedEvents, array $delta, ?array $oldResource, ?array $newResource): bool
    {
        abort(403);
    }

    public function onEventResize(array $event, array $oldEvent, array $relatedEvents, array $startDelta, array $endDelta): bool
    {
        abort(403);
    }

    protected function getEloquentQuery(): Builder
    {
        [$user, $business] = $this->authorizedContext();

        return app(BusinessAccess::class)->bookings($user, $business);
    }

    protected function headerActions(): array
    {
        return [];
    }

    protected function modalActions(): array
    {
        return BookingManagementActions::forCalendar(
            fn (BookingCalendarWidget $livewire): ?Booking => $livewire->getRecord(),
        );
    }

    protected function viewAction(): Action
    {
        return FullCalendarViewAction::make()
            ->modalHeading(fn (Booking $record): string => $record->service_name_snapshot)
            ->modalDescription(fn (Booking $record): string => 'Booking Reference: '.$record->reference)
            ->modalWidth('4xl')
            ->infolist(fn (Infolist $infolist): Infolist => $infolist->schema([
                Section::make('Appointment')
                    ->icon('heroicon-o-calendar-days')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextEntry::make('starts_at')
                            ->label('Booking date')
                            ->getStateUsing(fn (Booking $record): string => $record->starts_at
                                ->setTimezone($this->bookingTimezone($record))
                                ->format('l, F j, Y'))
                            ->size('lg'),
                        TextEntry::make('starts_at_time')
                            ->label('Start time')
                            ->getStateUsing(fn (Booking $record): string => $record->starts_at
                                ->setTimezone($this->bookingTimezone($record))
                                ->format('g:i A'))
                            ->size('lg'),
                        TextEntry::make('ends_at_time')
                            ->label('End time')
                            ->getStateUsing(fn (Booking $record): string => $record->ends_at
                                ->setTimezone($this->bookingTimezone($record))
                                ->format('g:i A')),
                        TextEntry::make('location.name')->label('Location'),
                        TextEntry::make('timezone')
                            ->label('Timezone')
                            ->getStateUsing(fn (Booking $record): string => $this->bookingTimezone($record)),
                    ]),
                Section::make('Booking information')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextEntry::make('customer.name')
                            ->label('Customer')
                            ->visible(fn (Booking $record): bool => $this->canViewCustomer($record)),
                        TextEntry::make('customer.phone')
                            ->label('Phone')
                            ->visible(fn (Booking $record): bool => $this->canViewCustomer($record)),
                        TextEntry::make('customer.email')
                            ->label('Email')
                            ->visible(fn (Booking $record): bool => $this->canViewCustomer($record) && filled($record->customer?->email)),
                        TextEntry::make('service_name_snapshot')->label('Service'),
                        TextEntry::make('location.name')->label('Branch'),
                        TextEntry::make('staff.name')
                            ->label('Staff')
                            ->visible(fn (Booking $record): bool => $record->staff_id !== null),
                        TextEntry::make('booking_mode')
                            ->label('Booking mode')
                            ->getStateUsing(fn (Booking $record): string => $this->bookingModeLabel($record)),
                        TextEntry::make('notes')
                            ->label('Notes')
                            ->visible(fn (Booking $record): bool => filled($record->notes) && Gate::allows('update', $record))
                            ->columnSpanFull(),
                    ]),
                Section::make('Booking status')
                    ->icon('heroicon-o-check-circle')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (BookingStatus $state): string => str($state->value)->headline()->toString())
                            ->color(fn (BookingStatus $state): string => $this->statusFilamentColor($state)),
                    ]),
            ]))
            ->form([]);
    }

    /** @return array{User, Business} */
    private function authorizedContext(): array
    {
        $user = Filament::auth()->user();
        $business = Filament::getTenant();

        abort_unless($user instanceof User && $business instanceof Business, 403);

        return [$user, $business];
    }

    private function selectedFilter(string $key): ?string
    {
        $value = $this->filters[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('A calendar filter has an invalid value.');
        }

        return (string) $value;
    }

    private function businessTimezone(): string
    {
        [, $business] = $this->authorizedContext();
        $timezone = $business->timezone ?: config('app.timezone');

        new DateTimeZone($timezone);

        return $timezone;
    }

    private function bookingTimezone(Booking $booking): string
    {
        $timezone = $booking->location?->timezone ?: $booking->business?->timezone ?: config('app.timezone');

        new DateTimeZone($timezone);

        return $timezone;
    }

    private function bookingModeLabel(Booking $booking): string
    {
        $value = DB::table('service_locations')
            ->where('service_id', $booking->service_id)
            ->where('location_id', $booking->location_id)
            ->value('booking_mode');

        return match (is_string($value) ? BookingMode::tryFrom($value) : null) {
            BookingMode::StaffRequired => 'Staff required',
            BookingMode::Staffless => 'No staff required',
            null => 'Unavailable',
        };
    }

    private function canViewCustomer(Booking $booking): bool
    {
        $user = Filament::auth()->user();
        $customer = $booking->customer;

        return $user instanceof User && $customer && Gate::forUser($user)->allows('view', $customer);
    }

    private function statusColor(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Held => '#D97706',
            BookingStatus::Confirmed => '#16A34A',
            BookingStatus::CheckedIn => '#2563EB',
            BookingStatus::Completed => '#6B7280',
            BookingStatus::Cancelled, BookingStatus::NoShow, BookingStatus::Expired => '#DC2626',
        };
    }

    private function statusFilamentColor(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Held => 'warning',
            BookingStatus::Confirmed, BookingStatus::CheckedIn => 'success',
            BookingStatus::Completed => 'gray',
            BookingStatus::Cancelled, BookingStatus::NoShow, BookingStatus::Expired => 'danger',
        };
    }
}
