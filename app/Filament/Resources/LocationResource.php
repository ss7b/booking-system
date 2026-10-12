<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages\ManageLocations;
use App\Models\AvailabilityCalendar;
use App\Models\Location;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class LocationResource extends TenantResource
{
    protected static ?string $model = Location::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(150),
                Forms\Components\TextInput::make('slug')->required()->alphaDash()->maxLength(150),
                Forms\Components\TextInput::make('timezone')->maxLength(64)->placeholder('Asia/Riyadh'),
                Forms\Components\Select::make('calendar_id')
                    ->label('Availability calendar')
                    ->options(fn () => AvailabilityCalendar::query()
                        ->where('business_id', Filament::getTenant()?->getKey())
                        ->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->nullable(),
                Forms\Components\Select::make('services')
                    ->relationship('services', 'name', modifyQueryUsing: fn ($query) => $query->where('business_id', Filament::getTenant()?->getKey()))
                    ->multiple()->preload(),
                Forms\Components\Textarea::make('address')->columnSpanFull(),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(50),
                Forms\Components\Toggle::make('is_active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('timezone'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('is_active')])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (Location $record): bool => ! $record->bookings()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLocations::route('/'),
        ];
    }
}
