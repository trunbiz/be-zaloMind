<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AgentCommand extends Model
{
    use HasUuids;

    public const COMPLETION_TIMEOUT_SECONDS = 43200;

    protected $guarded = [];

    protected $hidden = ['lease_token'];

    protected $casts = [
        'payload' => 'array', 'result' => 'array', 'lease_token' => 'encrypted',
        'lease_expires_at' => 'datetime', 'deadline_at' => 'datetime',
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'dispatched_at' => 'datetime',
        'cancel_requested' => 'boolean', 'attempt' => 'integer',
        'assignment_version' => 'integer', 'progress_seq' => 'integer',
        'available_at' => 'datetime', 'workflow' => 'array', 'quota_units' => 'integer',
    ];

    public function completionExpiresAt(): \Carbon\Carbon
    {
        return ($this->dispatched_at ?? $this->started_at ?? $this->created_at)->copy()->addSeconds(self::COMPLETION_TIMEOUT_SECONDS);
    }

    protected static function booted(): void
    {
        static::creating(function (self $command) {
            $command->execution_key ??= (string) Str::uuid();
        });
    }
}
