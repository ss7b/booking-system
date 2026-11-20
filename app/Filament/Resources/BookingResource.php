<?php

namespace App\Filament\Resources;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Booking\Services\CreateBooking;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Filament\Forms\Components\BookingAvailabilityPicker;
use App\Filament\Resources\BookingResource\BookingManagementActions;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
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
use Illuminate\Support\Facades\DB;

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
                TextColumn::make('staff.name')->label('Staff')->formatStateUsing(fn (?string $state): string => $state ?? '—')->sortable(),
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
                            ->live()->required()
                            ->afterStateUpdated(function (callable $set): void {
                                $set('service_id', null);
                                $set('staff_id', null);
                                $set('selected_date', null);
                                $set('calendar_week_start', null);
                                $set('starts_at', null);
                            }),
                        Select::make('service_id')
                            ->options(fn (Get $get) => Service::query()
                                ->where('business_id', Filament::getTenant()?->getKey())
                                ->where('is_active', true)
                                ->when($get('location_id'), fn ($query, $locationId) => $query->whereHas('locations', fn ($locations) => $locations->whereKey($locationId)->where('locations.is_active', true)))
                                ->orderBy('name')->pluck('name', 'id'))
                            ->live()->required()
                            ->afterStateUpdated(function (callable $set): void {
                                $set('staff_id', null);
                                $set('selected_date', null);
                                $set('calendar_week_start', null);
                                $set('starts_at', null);
                            }),
                        Select::make('staff_id')
                            ->options(fn (Get $get) => Staff::query()
                                ->where('business_id', Filament::getTenant()?->getKey())
                                ->where('status', 'active')
                                ->when($get('service_id'), fn ($query, $serviceId) => $query->whereHas('services', fn ($services) => $services->whereKey($serviceId)))
                                ->when($get('location_id'), fn ($query, $locationId) => $query->whereHas('locations', fn ($locations) => $locations->whereKey($locationId)->where('staff_locations.is_active', true)))
                                ->orderBy('name')->pluck('name', 'id'))
                            ->visible(fn (Get $get): bool => self::staffRequired($get('service_id'), $get('location_id')))
                            ->required(fn (Get $get): bool => self::staffRequired($get('service_id'), $get('location_id')))
                            ->live()
                            ->afterStateUpdated(function (callable $set): void {
                                $set('selected_date', null);
                                $set('calendar_week_start', null);
                                $set('starts_at', null);
                            }),
                        Hidden::make('selected_date')->dehydrated(false),
                        Hidden::make('calendar_week_start')->dehydrated(false),
                        BookingAvailabilityPicker::make('starts_at')
                            ->label('Appointment time')
                            ->columnSpanFull()
                            ->required(),
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
                        $staff = empty($data['staff_id'])
                            ? null
                            : Staff::query()
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
                                new DateTimeImmutable($data['starts_at'], new DateTimeZone($location->timezone ?: $business->timezone)),
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
                            $message = match ($exception->getMessage()) {
                                'CUSTOMER_CONTACT_CONFLICT' => 'Customer contact details match multiple customer records.',
                                'CUSTOMER_BOOKING_OVERLAP' => 'The customer already has a booking during this time.',
                                'CUSTOMER_SERVICE_DAILY_LIMIT' => 'You already have an active booking for this service on the selected date.',
                                default => $exception->getMessage(),
                            };

                            Notification::make()->title('Booking was not created')->body($message)->danger()->send();
                        }
                    }),
            ])
            ->actions(BookingManagementActions::forTable())
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
