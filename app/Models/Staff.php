<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Staff extends BookingModel
{
    protected static function booted(): void
    {
        static::saving(function (Staff $staff): void {
            if ($staff->user_id && ! DB::table('business_user')
                ->where('business_id', $staff->business_id)
                ->where('user_id', $staff->user_id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => 'The linked user must be a member of the same business.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(AvailabilityCalendar::class, 'calendar_id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'staff_services')->using(StaffService::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'staff_locations')->using(StaffLocation::class)->withPivot('is_active');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(StaffBlock::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}