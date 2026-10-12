<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)
            ->using(BusinessMembership::class)
            ->withPivot('role', 'access_scope')
            ->withTimestamps();
    }

    public function assignedLocations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'business_user_locations')
            ->withPivot('business_id', 'created_at');
    }

    public function staffProfiles(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->is_active
            && $this->businesses()->where('businesses.is_active', true)->exists();
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->businesses()->where('businesses.is_active', true)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Business
            && $this->is_active
            && $tenant->is_active
            && $this->businesses()->whereKey($tenant->getKey())->where('businesses.is_active', true)->exists();
    }
}
