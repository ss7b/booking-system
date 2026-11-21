<?php

namespace App\Filament\Resources;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Booking\Services\UpdateServiceLocationSettings;
use App\Enums\BookingMode;
use App\Filament\Resources\ServiceResource\Pages\ManageServices;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\User;
use DomainException;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceResource extends TenantResource
{
    protected static ?string $model = Service::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Service details')
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(200),
                        Forms\Components\TextInput::make('slug')->required()->alphaDash()->maxLength(200),
                        Forms\Components\Textarea::make('description')->columnSpanFull(),
                        Forms\Components\TextInput::make('duration_minutes')->numeric()->integer()->minValue(1)->required(),
                        Forms\Components\TextInput::make('slot_interval_minutes')->numeric()->integer()->minValue(1)->required(),
                        Forms\Components\TextInput::make('buffer_before_minutes')->numeric()->integer()->minValue(0)->default(0)->required(),
                        Forms\Components\TextInput::make('buffer_after_minutes')->numeric()->integer()->minValue(0)->default(0)->required(),
                        Forms\Components\TextInput::make('min_notice_minutes')->numeric()->integer()->minValue(0)->default(0)->required(),
                        Forms\Components\TextInput::make('max_advance_days')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->nullable()
                            ->helperText('0 prevents bookings on future days. A positive value sets the maximum advance period in days. Leave empty for no limit.'),
                        Forms\Components\TextInput::make('price_minor')->numeric()->integer()->minValue(0)->nullable(),
                        Forms\Components\TextInput::make('currency')->length(3)->default('SAR')->nullable(),
                        Forms\Components\Toggle::make('is_active')->default(true),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Booking settings per location')
                    ->description('Configure how this service is booked in each location.')
                    ->schema([
                        Forms\Components\Repeater::make('location_settings')
                            ->label('Locations')
                            ->schema([
                                Forms\Components\Select::make('location_id')
                                    ->label('Location')
                                    ->options(fn (): array => self::availableLocations())
                                    ->searchable()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->required(),
                                Forms\Components\Radio::make('booking_mode')
                                    ->label('Booking mode')
                                    ->options([
                                        BookingMode::StaffRequired->value => 'Requires staff',
                                        BookingMode::Staffless->value => 'No staff required',
                                    ])
                                    ->descriptions([
                                        BookingMode::StaffRequired->value => 'Customers choose an available staff member when booking.',
                                        BookingMode::Staffless->value => 'Bookings use the location calendar and service capacity.',
                                    ])
                                    ->default(BookingMode::StaffRequired->value)
                                    ->live()
                                    ->required(),
                                Forms\Components\TextInput::make('capacity')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required(fn (Get $get): bool => $get('booking_mode') === BookingMode::Staffless->value)
                                    ->dehydratedWhenHidden()
                                    ->helperText('Maximum number of overlapping bookings for this service at this location.')
                                    ->visible(fn (Get $get): bool => $get('booking_mode') === BookingMode::Staffless->value),
                                Forms\Components\Placeholder::make('availability_warning')
                                    ->label('Availability calendar')
                                    ->content('This location has no availability calendar. Customers will not see bookable dates until a calendar is configured.')
                                    ->visible(fn (Get $get): bool => self::locationHasNoCalendar($get('location_id'))
                                        && $get('booking_mode') === BookingMode::Staffless->value),
                            ])
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Add location')
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('locations')
            ->withCount([
                'locations as staffless_locations_count' => fn (Builder $query) => $query
                    ->where('service_locations.booking_mode', BookingMode::Staffless->value),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('duration_minutes')->suffix(' min')->sortable(),
                Tables\Columns\TextColumn::make('slot_interval_minutes')->label('Slot interval')->suffix(' min'),
                Tables\Columns\TextColumn::make('price_minor')->label('Price (minor)'),
                Tables\Columns\TextColumn::make('location_summary')
                    ->label('Booking locations')
                    ->state(fn (Service $record): string => sprintf(
                        '%d locations, %d staffless, %d require staff',
                        $record->locations_count,
                        $record->staffless_locations_count,
                        $record->locations_count - $record->staffless_locations_count,
                    )),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('is_active')])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->modalHeading(__('filament-actions::edit.single.label'))
                    ->fillForm(function (Service $record): array {
                        $record->load('locations');

                        return [
                            ...$record->attributesToArray(),
                            'location_settings' => $record->locations
                                ->map(fn (Location $location): array => [
                                    'location_id' => (string) $location->getKey(),
                                    'booking_mode' => $location->pivot->booking_mode->value,
                                    'capacity' => $location->pivot->capacity,
                                ])
                                ->all(),
                        ];
                    })
                    ->using(function (array $data, Service $record): Service {
                        $settings = array_values($data['location_settings'] ?? []);
                        unset($data['location_settings'], $data['id'], $data['business_id'], $data['created_at'], $data['updated_at']);

                        $business = Filament::getTenant();
                        $user = Filament::auth()->user();
                        abort_unless($business instanceof Business && $user instanceof User, 500);

                        return DB::transaction(function () use ($data, $record, $settings, $business, $user): Service {
                            self::saveLocationSettings($business, $record, $user, $settings);
                            $record->fill($data);
                            $record->save();

                            return $record;
                        }, attempts: 3);
                    }),
                Tables\Actions\DeleteAction::make()
                    ->modalHeading(__('filament-actions::delete.single.label'))
                    ->visible(fn (Service $record): bool => ! $record->bookings()->exists()),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function availableLocations(): array
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $business instanceof Business || ! $user instanceof User) {
            return [];
        }

        return app(BusinessAccess::class)->locations($user, $business)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function locationHasNoCalendar(mixed $locationId): bool
    {
        $business = Filament::getTenant();
        if (! $business instanceof Business || ! is_string($locationId) || $locationId === '') {
            return false;
        }

        return Location::query()
            ->where('business_id', $business->getKey())
            ->whereKey($locationId)
            ->whereNull('calendar_id')
            ->exists();
    }

    /**
     * @param  list<array{location_id: string, booking_mode: string, capacity: int|string}>  $settings
     */
    public static function saveLocationSettings(
        Business $business,
        Service $service,
        User $user,
        array $settings,
    ): void {
        $locationIds = array_values(array_unique(array_map(
            static fn (array $setting): string => (string) ($setting['location_id'] ?? ''),
            $settings,
        )));
        $authorizedCount = app(BusinessAccess::class)->locations($user, $business)
            ->whereIn('id', $locationIds)
            ->count();

        if (in_array('', $locationIds, true) || $authorizedCount !== count($locationIds)) {
            throw ValidationException::withMessages([
                'location_settings' => 'Choose only locations you are authorized to manage in this business.',
            ]);
        }

        try {
            app(UpdateServiceLocationSettings::class)
                ->executeManyWithinTransaction($business, $service, $settings);
        } catch (DomainException $exception) {
            $message = match ($exception->getMessage()) {
                'BOOKING_MODE_CHANGE_BLOCKED' => 'Booking mode cannot change while active future bookings or holds exist.',
                'CAPACITY_BELOW_CURRENT_OCCUPANCY' => 'Capacity cannot be lower than current future peak occupancy.',
                'CAPACITY_MUST_BE_POSITIVE' => 'Capacity must be at least one.',
                'LOCATION_REMOVAL_BLOCKED' => 'A location cannot be removed while it has active future bookings or holds.',
                'DUPLICATE_SERVICE_LOCATION' => 'Choose each location only once.',
                default => 'The booking settings could not be saved. Check the selected locations and try again.',
            };

            throw ValidationException::withMessages(['location_settings' => $message]);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageServices::route('/'),
        ];
    }
}
