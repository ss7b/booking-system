<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends BookingModel
{
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'metadata' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(BusinessMembership::class)
            ->withPivot('role', 'access_scope')
            ->withTimestamps();
    }

    public function membersWithLocationAccess(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_user_locations', 'business_id', 'user_id')
            ->withPivot('location_id', 'created_at');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function availabilityCalendars(): HasMany
    {
        return $this->hasMany(AvailabilityCalendar::class);
    }

}