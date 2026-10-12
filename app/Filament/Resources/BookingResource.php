<?php

namespace App\Filament\Resources;

use App\Domain\Booking\Services\CreateBooking;
use App\Domain\Booking\Services\RescheduleBooking;
use App\Domain\Booking\Services\TransitionBooking;
use App\Domain\Authorization\BusinessAccess;
use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Booking Management';

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $tenant instanceof Business || ! $user) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return app(BusinessAccess::class)->bookings($user, $tenant);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('customer.name')->label('Customer')->searchable(),
                TextColumn::make('service_name_snapshot')->label('Service')->searchable(),
                TextColumn::make('staff.name')->label('Staff')->sortable(),
                TextColumn::make('location.name')->label('Location'),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->timezone(fn (Booking $record): string => $record->location?->timezone ?: (Filament::getTenant()?->timezone ?? config('app.timezone')))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BookingStatus $state): string => $state->value)
                    ->color(fn (BookingStatus $state): string => match ($state) {
                        BookingStatus::Held => 'warning',
                        BookingStatus::Confirmed, BookingStatus::CheckedIn => 'success',
                        BookingStatus::Completed => 'gray',
                        BookingStatus::Cancelled, BookingStatus::NoShow, BookingStatus::Expired => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(BookingStatus::cases())->mapWithKeys(
                    fn (BookingStatus $status): array => [$status->value => str($status->value)->headline()->toString()],
                )),
            ])
            ->headerActions([
                Action::make('create_booking')
                    ->label('New booking')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => self::canManageCurrentBookings())
                    ->form([
                        Select::make('location_id')
                            ->options(fn () => app(BusinessAccess::class)->locations(Filament::auth()->user(), Filament::getTenant())->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->live()->required(),
                        Select::make('service_id')
                            ->options(fn (Get $get) => Service::query()
                                ->where('business_id', Filament::getTenant()?->getKey())
                                ->where('is_active', true)
                                ->when($get('location_id'), fn ($query, $locationId) => $query->whereHas('locations', fn ($locations) => $locations->whereKey($locationId)->where('locations.is_active', true)))
                                ->orderBy('name')->pluck('name', 'id'))
                            ->live()->required(),
                        Select::make('staff_id')
                            ->options(fn (Get $get) => Staff::query()
                                ->where('business_id', Filament::getTenant()?->getKey())
                                ->where('status', 'active')
                                ->when($get('service_id'), fn ($query, $serviceId) => $query->whereHas('services', fn ($services) => $services->whereKey($serviceId)))
                                ->when($get('location_id'), fn ($query, $locationId) => $query->whereHas('locations', fn ($locations) => $locations->whereKey($locationId)->where('staff_locations.is_active', true)))
                                ->orderBy('name')->pluck('name', 'id'))
                            ->required(),
                        DateTimePicker::make('starts_at')
                            ->timezone(fn (Get $get): string => Location::query()
                                ->where('business_id', Filament::getTenant()?->getKey())
                                ->whereKey($get('location_id'))
                                ->value('timezone') ?: (Filament::getTenant()?->timezone ?? config('app.timezone')))
                            ->seconds(false)->required(),
                        TextInput::make('customer_name')->label('Customer name')->required()->maxLength(200),
                        TextInput::make('customer_phone')->label('Customer phone')->tel()->required()->maxLength(50),
                        TextInput::make('customer_email')->label('Customer email')->email()->maxLength(255)->nullable(),
                        Textarea::make('notes')->maxLength(5000)->nullable()->columnSpanFull(),
                    ])
                    ->action(function (array $data): void {
                        $business = Filament::getTenant();
                        abort_unless($business instanceof Business, 500);
                        abort_unless(self::canManageCurrentBookings(), 403);

                        $location = Location::query()
                            ->where('business_id', $business->getKey())
                            ->where('is_active', true)
                            ->findOrFail($data['location_id']);
                        abort_unless(app(BusinessAccess::class)->canAccessLocation(Filament::auth()->user(), $business, $location), 404);
                        $service = Service::query()
                            ->where('business_id', $business->getKey())
                            ->where('is_active', true)
                            ->whereHas('locations', fn ($query) => $query->whereKey($location->getKey())->where('locations.is_active', true))
                            ->findOrFail($data['service_id']);
                        $staff = Staff::query()
                            ->where('business_id', $business->getKey())
                            ->where('status', 'active')
                            ->whereHas('services', fn ($query) => $query->whereKey($service->getKey()))
                            ->whereHas('locations', fn ($query) => $query->whereKey($location->getKey())->where('staff_locations.is_active', true))
                            ->findOrFail($data['staff_id']);

                        try {
                            $result = app(CreateBooking::class)->execute(
                                $business,
                                $location,
                                $service,
                                $staff,
                                new DateTimeImmutable($data['starts_at'], new DateTimeZone(config('app.timezone'))),
                                [
                                    'name' => $data['customer_name'],
                                    'phone' => $data['customer_phone'],
                                    'email' => $data['customer_email'] ?? null,
                                ],
                                $data['notes'] ?? null,
                                false,
                                'admin',
                                'user',
                                (string) Filament::auth()->id(),
                            );

                            Notification::make()->title('Booking created')->body($result->booking->reference)->success()->send();
                        } catch (DomainException $exception) {
                            Notification::make()->title('Booking was not created')->body($exception->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->actions([
                Action::make('confirm')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Held)
                    ->action(function (Booking $record): void {
                        self::authorizeBookingAction($record);
                        app(TransitionBooking::class)->execute($record, BookingStatus::Confirmed, null, 'user', (string) Filament::auth()->id());
                    }),
                Action::make('cancel')
                    ->color('danger')
                    ->form([Textarea::make('reason')->maxLength(255)])
                    ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Held, BookingStatus::Confirmed], true))
                    ->action(function (Booking $record, array $data): void {
                        self::authorizeBookingAction($record);
                        app(TransitionBooking::class)->execute($record, BookingStatus::Cancelled, $data['reason'] ?? null, 'user', (string) Filament::auth()->id());
                    }),
                Action::make('check_in')
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::Confirmed)
                    ->action(function (Booking $record): void {
                        self::authorizeBookingAction($record);
                        app(TransitionBooking::class)->execute($record, BookingStatus::CheckedIn, null, 'user', (string) Filament::auth()->id());
                    }),
                Action::make('complete')
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::CheckedIn)
                    ->action(function (Booking $record): void {
                        self::authorizeBookingAction($record);
                        app(TransitionBooking::class)->execute($record, BookingStatus::Completed, null, 'user', (string) Filament::auth()->id());
                    }),
                Action::make('no_show')
                    ->color('warning')
                    ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true))
                    ->action(function (Booking $record): void {
                        self::authorizeBookingAction($record);
                        app(TransitionBooking::class)->execute($record, BookingStatus::NoShow, null, 'user', (string) Filament::auth()->id());
                    }),
                Action::make('reschedule')
                    ->label('Reschedule')
                    ->icon('heroicon-o-calendar')
                    ->visible(fn (Booking $record): bool => in_array($record->status, [BookingStatus::Held, BookingStatus::Confirmed], true))
                    ->fillForm(fn (Booking $record): array => [
                        'location_id' => $record->location_id,
                        'staff_id' => $record->staff_id,
                        'starts_at' => $record->starts_at,
                    ])
                    ->form([
                        Select::make('location_id')
                            ->options(fn () => Location::query()->where('business_id', Filament::getTenant()?->getKey())->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->live()->required(),
                        Select::make('staff_id')
                            ->options(fn (Get $get, Booking $record) => Staff::query()
                                ->where('business_id', Filament::getTenant()?->getKey())
                                ->where('status', 'active')
                                ->whereHas('services', fn ($query) => $query->whereKey($record->service_id))
                                ->when($get('location_id'), fn ($query, $locationId) => $query->whereHas('locations', fn ($locations) => $locations->whereKey($locationId)->where('staff_locations.is_active', true)))
                                ->orderBy('name')->pluck('name', 'id'))
                            ->required(),
                        DateTimePicker::make('starts_at')
                            ->timezone(fn (Get $get): string => Location::query()
                                ->where('business_id', Filament::getTenant()?->getKey())
                                ->whereKey($get('location_id'))
                                ->value('timezone') ?: (Filament::getTenant()?->timezone ?? config('app.timezone')))
                            ->seconds(false)->required(),
                        Textarea::make('reason')->maxLength(255)->nullable(),
                    ])
                    ->action(function (Booking $record, array $data): void {
                        $business = Filament::getTenant();
                        abort_unless($business instanceof Business, 500);
                        self::authorizeBookingAction($record);
                        $location = Location::query()->where('business_id', $business->getKey())->where('is_active', true)->findOrFail($data['location_id']);
                        abort_unless(app(BusinessAccess::class)->canAccessLocation(Filament::auth()->user(), $business, $location), 404);
                        $staff = Staff::query()->where('business_id', $business->getKey())->where('status', 'active')->findOrFail($data['staff_id']);

                        try {
                            $updated = app(RescheduleBooking::class)->execute(
                                $business,
                                $record,
                                $location,
                                $staff,
                                new DateTimeImmutable($data['starts_at'], new DateTimeZone(config('app.timezone'))),
                                'user',
                                (string) Filament::auth()->id(),
                                $data['reason'] ?? null,
                            );

                            Notification::make()->title('Booking rescheduled')->body($updated->reference)->success()->send();
                        } catch (DomainException $exception) {
                            Notification::make()->title('Booking was not rescheduled')->body($exception->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('starts_at');
    }

    public static function getPages(): array
    {
        return ['index' => ListBookings::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    private static function canManageCurrentBookings(): bool
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        return $business instanceof Business && $user && app(BusinessAccess::class)->canManageBookings($user, $business);
    }

    private static function authorizeBookingAction(Booking $record): void
    {
        abort_unless(app(BusinessAccess::class)->canManageBooking(Filament::auth()->user(), $record), 403);
    }
}