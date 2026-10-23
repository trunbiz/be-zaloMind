<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AgentProfile extends Model
{
    use HasUuids;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $guarded = [];

    protected $attributes = ['status' => 'disconnected', 'version' => 1, 'assignment_version' => 1, 'last_sequence' => 0];

    protected $casts = ['qr' => 'encrypted:array', 'last_seen_at' => 'datetime', 'assignment_version' => 'integer', 'last_sequence' => 'integer'];
}
