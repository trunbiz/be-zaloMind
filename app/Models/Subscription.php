<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['limits_snapshot' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
}
