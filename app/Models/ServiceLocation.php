<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

class ServiceLocation extends Pivot
{
    protected $table = 'service_locations';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (ServiceLocation $assignment): void {
            $serviceBusinessId = Service::query()->whereKey($assignment->service_id)->value('business_id');
            $locationBusinessId = Location::query()->whereKey($assignment->location_id)->value('business_id');

            if (! $serviceBusinessId || (string) $serviceBusinessId !== (string) $locationBusinessId) {
                throw ValidationException::withMessages(['location_id' => 'Service and location must belong to the same business.']);
            }
        });
    }
}
