<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

class StaffService extends Pivot
{
    protected $table = 'staff_services';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (StaffService $assignment): void {
            $staffBusinessId = Staff::query()->whereKey($assignment->staff_id)->value('business_id');
            $serviceBusinessId = Service::query()->whereKey($assignment->service_id)->value('business_id');

            if (! $staffBusinessId || (string) $staffBusinessId !== (string) $serviceBusinessId) {
                throw ValidationException::withMessages(['service_id' => 'Staff and service must belong to the same business.']);
            }
        });
    }
}
