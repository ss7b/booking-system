<?php

namespace App\Models;

use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BusinessMembership extends Pivot
{
    protected $table = 'business_user';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role' => BusinessRole::class,
            'access_scope' => BranchAccessScope::class,
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(
            Location::class,
            'business_user_locations',
            'user_id',
            'location_id',
            'user_id',
            'id',
        )->wherePivot('business_id', $this->business_id);
    }
}
