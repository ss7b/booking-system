<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PendingEmailChange extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'email',
        'token_hash',
        'credential_hash',
        'expires_at',
    ];

    protected $hidden = ['token_hash', 'credential_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
