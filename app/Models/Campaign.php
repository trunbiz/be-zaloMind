<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $attributes = ['status' => 'scheduled', 'version' => 1];
}
