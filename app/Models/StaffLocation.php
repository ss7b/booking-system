<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

class StaffLocation extends Pivot
{
    protected $table = 'staff_locations';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (StaffLocation $assignment): void {
            $staffBusinessId = Staff::query()->whereKey($assignment->staff_id)->value('business_id');
            $locationBusinessId = Location::query()->whereKey($assignment->location_id)->value('business_id');

            if (! $staffBusinessId || (string) $staffBusinessId !== (string) $locationBusinessId) {
                throw ValidationException::withMessages(['location_id' => 'Staff and location must belong to the same business.']);
            }
        });
    }
}
