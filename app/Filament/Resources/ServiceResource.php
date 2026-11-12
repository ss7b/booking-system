<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceResource\Pages\ManageServices;
use App\Domain\Booking\Services\UpdateServiceLocationSettings;
use App\Enums\BookingMode;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceLocation;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use DomainException;

class ServiceResource extends TenantResource
{
    protected static ?string $model = Service::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(200),
                Forms\Components\TextInput::make('slug')->required()->alphaDash()->maxLength(200),
                Forms\Components\Textarea::make('description')->columnSpanFull(),
                Forms\Components\Select::make('locations')
                    ->relationship('locations', 'name', modifyQueryUsing: fn ($query) => $query->whereIn('locations.id', app(\App\Domain\Authorization\BusinessAccess::class)->locations(Filament::auth()->user(), Filament::getTenant())->select('id')))
                    ->multiple()->preload(),
                Forms\Components\TextInput::make('duration_minutes')->numeric()->integer()->minValue(1)->required(),
                Forms\Components\TextInput::make('slot_interval_minutes')->numeric()->integer()->minValue(1)->required(),
                Forms\Components\TextInput::make('buffer_before_minutes')->numeric()->integer()->minValue(0)->default(0)->required(),
                Forms\Components\TextInput::make('buffer_after_minutes')->numeric()->integer()->minValue(0)->default(0)->required(),
                Forms\Components\TextInput::make('min_notice_minutes')->numeric()->integer()->minValue(0)->default(0)->required(),
                Forms\Components\TextInput::make('max_advance_days')->numeric()->integer()->minValue(0)->nullable(),
                Forms\Components\TextInput::make('price_minor')->numeric()->integer()->minValue(0)->nullable(),
                Forms\Components\TextInput::make('currency')->length(3)->default('SAR')->nullable(),
                Forms\Components\Toggle::make('is_active')->default(true),
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
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('is_active')])
            ->actions([
                Tables\Actions\Action::make('configure_location_booking')
                    ->label('Configure booking per location')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->form([
                        Forms\Components\Select::make('location_id')
                            ->label('Location')
                            ->options(fn (Service $record) => $record->locations()
                                ->whereIn('locations.id', app(\App\Domain\Authorization\BusinessAccess::class)
                                    ->locations(Filament::auth()->user(), Filament::getTenant())
                                    ->select('id'))
                                ->orderBy('locations.name')
                                ->pluck('locations.name', 'locations.id'))
                            ->live()
                            ->required()
                            ->afterStateUpdated(function (?string $state, callable $set, Service $record): void {
                                if (! $state) {
                                    return;
                                }

                                $assignment = ServiceLocation::query()
                                    ->where('service_id', $record->getKey())
                                    ->where('location_id', $state)
                                    ->first();
                                $set('booking_mode', $assignment?->booking_mode?->value ?? BookingMode::StaffRequired->value);
                                $set('capacity', $assignment?->capacity ?? 1);
                            }),
                        Forms\Components\Select::make('booking_mode')
                            ->options([
                                BookingMode::StaffRequired->value => 'Requires staff',
                                BookingMode::Staffless->value => 'Staffless',
                            ])
                            ->live()
                            ->required(),
                        Forms\Components\TextInput::make('capacity')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->dehydratedWhenHidden()
                            ->visible(fn (Get $get): bool => $get('booking_mode') === BookingMode::Staffless->value),
                    ])
                    ->action(function (Service $record, array $data): void {
                        $business = Filament::getTenant();
                        $user = Filament::auth()->user();
                        abort_unless($business instanceof Business && $user, 500);

                        $access = app(\App\Domain\Authorization\BusinessAccess::class);
                        abort_unless($access->canManageService($user, $record), 403);

                        $location = Location::query()
                            ->where('business_id', $business->getKey())
                            ->findOrFail($data['location_id']);
                        abort_unless($access->canAccessLocation($user, $business, $location), 404);
                        $mode = BookingMode::tryFrom((string) $data['booking_mode']);
                        abort_unless($mode !== null, 422);

                        try {
                            app(UpdateServiceLocationSettings::class)->execute(
                                $business,
                                $record,
                                $location,
                                $mode,
                                (int) ($data['capacity'] ?? 1),
                            );

                            Notification::make()->title('Location booking settings saved')->success()->send();
                        } catch (DomainException $exception) {
                            $message = match ($exception->getMessage()) {
                                'BOOKING_MODE_CHANGE_BLOCKED' => 'Booking mode cannot change while active future bookings or holds exist.',
                                'CAPACITY_BELOW_CURRENT_OCCUPANCY' => 'Capacity cannot be lower than current future peak occupancy.',
                                'CAPACITY_MUST_BE_POSITIVE' => 'Capacity must be at least one.',
                                default => $exception->getMessage(),
                            };

                            Notification::make()->title('Booking settings were not saved')->body($message)->danger()->send();
                        }
                    })
                    ->visible(fn (Service $record): bool => app(\App\Domain\Authorization\BusinessAccess::class)
                        ->canManageService(Filament::auth()->user(), $record)),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (Service $record): bool => ! $record->bookings()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageServices::route('/'),
        ];
    }
}
