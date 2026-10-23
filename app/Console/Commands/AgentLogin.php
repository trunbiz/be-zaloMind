<?php

namespace App\Console\Commands;

use App\Models\AgentProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AgentLogin extends Command
{
    protected $signature = 'agent:login {profile : Profile UUID}';

    protected $description = 'Queue a QR login command for the default agent';

    public function handle(): int
    {
        return DB::transaction(function () {
            DB::table('agent_runtime')->where('id', 1)->lockForUpdate()->first();
            $profile = AgentProfile::find($this->argument('profile'));
            if (! $profile) {
                $this->error('Profile not found.');

                return self::FAILURE;
            }
            $result = app(\App\Services\ProfileLifecycle::class)->request(\App\Models\User::findOrFail($profile->user_id), $profile, 'login');
            $this->info($result['message']);

            return self::SUCCESS;
        });
    }
}
