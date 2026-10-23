<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkspaceItem extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $attributes = ['status' => 'active', 'version' => 1];

    protected $casts = ['data' => 'encrypted:array'];
}
