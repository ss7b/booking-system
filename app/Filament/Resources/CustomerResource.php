<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages\ManageCustomers;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends TenantResource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(200),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(50)->nullable(),
                Forms\Components\TextInput::make('normalized_phone')->maxLength(50)->nullable(),
                Forms\Components\TextInput::make('email')->email()->maxLength(255)->nullable(),
                Forms\Components\KeyValue::make('metadata')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('bookings_count')->counts('bookings')->label('Bookings')->sortable(),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (Customer $record): bool => ! $record->bookings()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCustomers::route('/'),
        ];
    }
}
