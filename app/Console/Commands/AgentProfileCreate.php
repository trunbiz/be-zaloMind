<?php

namespace App\Console\Commands;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Console\Command;

class AgentProfileCreate extends Command
{
    protected $signature = 'agent:profile {email : Existing owner email} {name : Profile display name}';

    protected $description = 'Create a profile assigned to the default agent for integration';

    public function handle(): int
    {
        $owner = User::where('email', $this->argument('email'))->first();
        $name = trim($this->argument('name'));
        if (! $owner || $name === '' || mb_strlen($name) > 255) {
            $this->error('Owner must exist and name must contain 1–255 characters.');

            return self::FAILURE;
        }
        $profile = AgentProfile::create(['user_id' => $owner->id, 'display_name' => $name]);
        $this->info('Profile ID: '.$profile->id);

        return self::SUCCESS;
    }
}
