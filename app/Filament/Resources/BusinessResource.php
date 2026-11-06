<?php

namespace App\Filament\Resources;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Booking\Services\BookingManagementLink;
use App\Filament\Resources\BusinessResource\Pages\ManageBusinesses;
use App\Models\Business;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ClosureValidationRule;

class BusinessResource extends TenantResource
{
    protected static ?string $model = Business::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Business Setup';

    public static function getEloquentQuery(): Builder
    {
        return Business::query()->whereHas('users', fn (Builder $query) => $query->whereKey(Filament::auth()->id()));
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(150),
                Forms\Components\TextInput::make('slug')->required()->alphaDash()->maxLength(150)->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('timezone')->required()->maxLength(64)->placeholder('Asia/Riyadh'),
                Forms\Components\TextInput::make('default_currency')->length(3)->required()->default('SAR'),
                Forms\Components\Toggle::make('is_active')->default(true),
                Forms\Components\TextInput::make('booking_management_url')
                    ->label('Booking Management URL')
                    ->placeholder('https://clinic.com/manage-booking')
                    ->helperText('Use HTTPS outside local and testing environments.')
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Business $record): void {
                        $value = data_get($record?->metadata, 'booking_management_url');
                        $component->state(is_string($value) ? $value : '');
                    })
                    ->rules([
                        new ClosureValidationRule(function (string $attribute, mixed $value, \Closure $fail): void {
                            if (filled($value) && ! BookingManagementLink::isValidManagementUrl($value)) {
                                $fail('Enter a valid management URL. HTTPS is required except for local development URLs in local or testing environments.');
                            }
                        }),
                    ]),
                Forms\Components\KeyValue::make('metadata')
                    ->dehydrateStateUsing(function ($state, Get $get): array {
                        $metadata = is_array($state) ? $state : [];
                        $url = trim((string) $get('booking_management_url'));
                        if ($url === '') {
                            unset($metadata['booking_management_url']);
                        } else {
                            $metadata['booking_management_url'] = $url;
                        }

                        return $metadata;
                    })
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('timezone'),
                Tables\Columns\TextColumn::make('default_currency'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('is_active')])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Business $record): bool => app(BusinessAccess::class)->canManageBusinessSettings(Filament::auth()->user(), $record)
                        && $record->getKey() !== Filament::getTenant()?->getKey() && self::hasNoTenantData($record))
                    ->action(fn (Business $record) => DB::transaction(function () use ($record): void {
                        abort_unless(app(BusinessAccess::class)->canManageBusinessSettings(Filament::auth()->user(), $record), 403);
                        $record->users()->detach();
                        $record->delete();
                    })),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBusinesses::route('/'),
        ];
    }

    private static function hasNoTenantData(Business $business): bool
    {
        foreach (['locations', 'services', 'staff', 'customers', 'bookings', 'availability_calendars', 'staff_blocks', 'booking_day_locks', 'idempotency_keys', 'outbox_events'] as $table) {
            if (DB::table($table)->where('business_id', $business->getKey())->exists()) {
                return false;
            }
        }

        return true;
    }
}
