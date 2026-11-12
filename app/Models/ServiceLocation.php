<?php

namespace App\Models;

use App\Enums\BookingMode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

class ServiceLocation extends Pivot
{
    protected $table = 'service_locations';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'booking_mode' => BookingMode::class,
            'capacity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ServiceLocation $assignment): void {
            $assignment->booking_mode ??= BookingMode::StaffRequired;
            $assignment->capacity ??= 1;

            if (! $assignment->booking_mode instanceof BookingMode || (int) $assignment->capacity < 1) {
                throw ValidationException::withMessages([
                    'booking_mode' => 'Choose a valid booking mode and a capacity of at least one.',
                ]);
            }

            $serviceBusinessId = Service::query()->whereKey($assignment->service_id)->value('business_id');
            $locationBusinessId = Location::query()->whereKey($assignment->location_id)->value('business_id');

            if (! $serviceBusinessId || (string) $serviceBusinessId !== (string) $locationBusinessId) {
                throw ValidationException::withMessages(['location_id' => 'Service and location must belong to the same business.']);
            }
        });
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
