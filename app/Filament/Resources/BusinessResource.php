<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BusinessResource\Pages\ManageBusinesses;
use App\Models\Business;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
                Forms\Components\KeyValue::make('metadata')->columnSpanFull(),
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
                    ->visible(fn (Business $record): bool => app(\App\Domain\Authorization\BusinessAccess::class)->canManageBusinessSettings(Filament::auth()->user(), $record)
                        && $record->getKey() !== Filament::getTenant()?->getKey() && self::hasNoTenantData($record))
                    ->action(fn (Business $record) => DB::transaction(function () use ($record): void {
                        abort_unless(app(\App\Domain\Authorization\BusinessAccess::class)->canManageBusinessSettings(Filament::auth()->user(), $record), 403);
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
