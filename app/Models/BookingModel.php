<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

abstract class BookingModel extends Model
{
    use HasUlids;

    protected $guarded = [];
}