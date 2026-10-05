<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceResource\Pages\ManageServices;
use App\Models\Service;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class ServiceResource extends TenantResource
{
    protected static ?string $model = Service::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(200),
                Forms\Components\TextInput::make('slug')->required()->alphaDash()->maxLength(200),
                Forms\Components\Textarea::make('description')->columnSpanFull(),
                Forms\Components\Select::make('locations')
                    ->relationship('locations', 'name', modifyQueryUsing: fn ($query) => $query->where('business_id', Filament::getTenant()?->getKey()))
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
