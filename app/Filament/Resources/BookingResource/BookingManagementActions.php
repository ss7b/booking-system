<?php

namespace App\Filament\Resources\BookingResource;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Booking\Services\RescheduleBooking;
use App\Domain\Booking\Services\TransitionBooking;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Filament\Forms\Components\BookingAvailabilityPicker;
use App\Filament\Widgets\BookingCalendarWidget;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Staff;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Filament\Actions\Action as FilamentAction;
use Filament\Actions\MountableAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class BookingManagementActions
{
    /**
     * @return array<MountableAction>
     */
    public static function forTable(): array
    {
        return self::makeActions(TableAction::class);
    }

    /**
     * @return array<MountableAction>
     */
    public static function forCalendar(\Closure $recordResolver): array
    {
        return self::makeActions(FilamentAction::class, $recordResolver);
    }

    /**
     * @param  class-string<MountableAction>  $actionClass
     * @return array<MountableAction>
     */
    private static function makeActions(string $actionClass, ?\Closure $recordResolver = null): array
    {
        $make = fn (string $name): MountableAction => self::makeAction($actionClass, $name, $recordResolver);

        return [
            $make('confirm')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Held && self::allowsForDisplay('confirm', $record))
                ->action(fn (Booking $record) => self::transition(
                    $record,
                    BookingStatus::Confirmed,
                    'confirm',
                    'Booking confirmed',
                )),
            $make('cancel')
                ->color('danger')
                ->form([Textarea::make('reason')->maxLength(255)])
                ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Held, BookingStatus::Confirmed], true)
                    && self::allowsForDisplay('cancel', $record))
                ->action(fn (Booking $record, array $data) => self::transition(
                    $record,
                    BookingStatus::Cancelled,
                    'cancel',
                    'Booking cancelled',
                    $data['reason'] ?? null,
                )),
            $make('check_in')
                ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Confirmed && self::allowsForDisplay('update', $record))
                ->action(fn (Booking $record) => self::transition(
                    $record,
                    BookingStatus::CheckedIn,
                    'update',
                    'Booking checked in',
                )),
            $make('complete')
                ->visible(fn (Booking $record): bool => $record->status === BookingStatus::CheckedIn && self::allowsForDisplay('update', $record))
                ->action(fn (Booking $record) => self::transition(
                    $record,
                    BookingStatus::Completed,
                    'update',
                    'Booking completed',
                )),
            $make('no_show')
                ->color('warning')
                ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true)
                    && self::allowsForDisplay('update', $record))
                ->action(fn (Booking $record) => self::transition(
                    $record,
                    BookingStatus::NoShow,
                    'update',
                    'Booking marked as no-show',
                )),
            $make('reschedule')
                ->label('Reschedule')
                ->icon('heroicon-o-calendar')
                ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Held, BookingStatus::Confirmed], true)
                    && self::allowsForDisplay('reschedule', $record))
                ->fillForm(fn (Booking $record): array => [
                    'location_id' => $record->location_id,
                    'service_id' => $record->service_id,
                    'staff_id' => $record->staff_id,
                    'starts_at' => $record->starts_at,
                ])
                ->form([
                    Select::make('location_id')
                        ->options(fn (): array => self::authorizedLocations()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (callable $set): void {
                            $set('staff_id', null);
                            $set('selected_date', null);
                            $set('calendar_week_start', null);
                            $set('starts_at', null);
                        }),
                    Select::make('staff_id')
                        ->options(fn (Get $get, Booking $record): array => self::staffOptions($record, $get('location_id')))
                        ->visible(fn (Get $get, Booking $record): bool => self::staffRequired($record->service_id, $get('location_id')))
                        ->required(fn (Get $get, Booking $record): bool => self::staffRequired($record->service_id, $get('location_id')))
                        ->live()
                        ->afterStateUpdated(function (callable $set): void {
                            $set('selected_date', null);
                            $set('calendar_week_start', null);
                            $set('starts_at', null);
                        }),
                    Hidden::make('service_id')->dehydrated(false),
                    Hidden::make('selected_date')->dehydrated(false),
                    Hidden::make('calendar_week_start')->dehydrated(false),
                    BookingAvailabilityPicker::make('starts_at')
                        ->label('Appointment time')
                        ->columnSpanFull()
                        ->required(),
                    Textarea::make('reason')->maxLength(255)->nullable(),
                ])
                ->action(fn (Booking $record, array $data) => self::reschedule($record, $data)),
        ];
    }

    private static function makeAction(string $actionClass, string $name, ?\Closure $recordResolver): MountableAction
    {
        $action = $actionClass::make($name);

        if ($recordResolver !== null) {
            $action->record($recordResolver);
            $action->after(fn (BookingCalendarWidget $livewire): mixed => $livewire->refreshAfterAction());
        }

        return $action;
    }

    private static function allowsForDisplay(string $ability, Booking $record): bool
    {
        $cacheKey = implode(':', [
            $ability,
            (string) Filament::auth()->id(),
            (string) Filament::getTenant()?->getKey(),
            (string) $record->getKey(),
        ]);
        $attribute = self::class.'.display_authorization';
        $cache = request()->attributes->get($attribute, []);
        if (! is_array($cache)) {
            $cache = [];
        }

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $cache[$cacheKey] = Gate::allows($ability, $record);
        request()->attributes->set($attribute, $cache);

        return $cache[$cacheKey];
    }

    private static function transition(
        Booking $record,
        BookingStatus $target,
        string $ability,
        string $successTitle,
        ?string $reason = null,
    ): void {
        Gate::authorize($ability, $record);

        try {
            $updated = app(TransitionBooking::class)->execute(
                $record,
                $target,
                $reason,
                'user',
                (string) Filament::auth()->id(),
            );
        } catch (DomainException $exception) {
            self::notifyFailure('Booking was not updated', $exception);
        }

        Notification::make()->title($successTitle)->body($updated->reference)->success()->send();
    }

    private static function reschedule(Booking $record, array $data): void
    {
        $user = Filament::auth()->user();
        $business = Filament::getTenant();
        abort_unless($user instanceof User && $business instanceof Business, 403);
        Gate::authorize('reschedule', $record);

        $access = app(BusinessAccess::class);
        $location = $access->locations($user, $business)
            ->where('is_active', true)
            ->findOrFail($data['location_id']);
        abort_unless($access->canAccessLocation($user, $business, $location), 404);

        $staff = empty($data['staff_id'])
            ? null
            : Staff::query()
                ->where('business_id', $business->getKey())
                ->where('status', 'active')
                ->whereHas('services', fn ($query) => $query->whereKey($record->service_id))
                ->whereHas('locations', fn ($query) => $query->whereKey($location->getKey())->where('staff_locations.is_active', true))
                ->findOrFail($data['staff_id']);

        try {
            $updated = app(RescheduleBooking::class)->execute(
                $business,
                $record,
                $location,
                $staff,
                new DateTimeImmutable($data['starts_at'], new DateTimeZone($location->timezone ?: $business->timezone)),
                'user',
                (string) Filament::auth()->id(),
                $data['reason'] ?? null,
            );
        } catch (DomainException $exception) {
            self::notifyFailure('Booking was not rescheduled', $exception);
        }

        Notification::make()->title('Booking rescheduled')->body($updated->reference)->success()->send();
    }

    private static function notifyFailure(string $title, DomainException $exception): never
    {
        $message = match ($exception->getMessage()) {
            'CUSTOMER_BOOKING_OVERLAP' => 'The customer already has a booking during this time.',
            'CUSTOMER_SERVICE_DAILY_LIMIT' => 'The customer already has an active booking for this service on this date.',
            'SLOT_UNAVAILABLE' => 'The selected time is no longer available.',
            'BOOKING_EXPIRED' => 'This booking hold has expired.',
            'BOOKING_CHANGED_RETRY' => 'The booking changed while the action was processing. Refresh and try again.',
            'INVALID_BOOKING_TRANSITION' => 'The booking status no longer allows this action.',
            default => $exception->getMessage(),
        };

        Notification::make()->title($title)->body($message)->danger()->send();

        throw new Halt;
    }

    private static function authorizedLocations(): Builder
    {
        $user = Filament::auth()->user();
        $business = Filament::getTenant();
        abort_unless($user instanceof User && $business instanceof Business, 403);

        return app(BusinessAccess::class)->locations($user, $business);
    }

    private static function staffOptions(Booking $record, mixed $locationId): array
    {
        $locationIds = self::authorizedLocations()->select('id');

        return Staff::query()
            ->where('business_id', Filament::getTenant()?->getKey())
            ->where('status', 'active')
            ->whereHas('services', fn ($query) => $query->whereKey($record->service_id))
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('staff_locations')
                ->whereColumn('staff_locations.staff_id', 'staff.id')
                ->whereIn('staff_locations.location_id', $locationIds)
                ->where('staff_locations.is_active', true)
                ->when($locationId, fn ($query) => $query->where('staff_locations.location_id', $locationId)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function locationTimezone(mixed $locationId): string
    {
        $timezone = self::authorizedLocations()
            ->whereKey($locationId)
            ->value('timezone');

        return $timezone ?: (Filament::getTenant()?->timezone ?? config('app.timezone'));
    }

    private static function staffRequired(?string $serviceId, ?string $locationId): bool
    {
        if (! $serviceId || ! $locationId) {
            return false;
        }

        return DB::table('service_locations')
            ->where('service_id', $serviceId)
            ->where('location_id', $locationId)
            ->value('booking_mode') === BookingMode::StaffRequired->value;
    }
}
