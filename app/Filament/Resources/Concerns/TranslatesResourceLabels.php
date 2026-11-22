<?php

namespace App\Filament\Resources\Concerns;

use Illuminate\Support\Str;

trait TranslatesResourceLabels
{
    public static function getModelLabel(): string
    {
        return __('resources.'.Str::snake(class_basename(static::getModel())).'.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('resources.'.Str::snake(class_basename(static::getModel())).'.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('resources.'.Str::snake(class_basename(static::getModel())).'.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return match (static::$navigationGroup) {
            'Booking Management' => __('navigation.groups.booking_management'),
            'Business Setup' => __('navigation.groups.business_setup'),
            default => static::$navigationGroup,
        };
    }
}
