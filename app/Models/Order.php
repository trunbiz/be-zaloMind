<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['plan_snapshot' => 'array', 'paid_at' => 'datetime', 'expires_at' => 'datetime', 'amount_vnd' => 'integer'];
}
