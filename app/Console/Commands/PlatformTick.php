<?php

namespace App\Console\Commands;

use App\Services\PlatformEngine;
use Illuminate\Console\Command;

class PlatformTick extends Command
{
    protected $signature = 'platform:tick';

    protected $description = 'Dispatch due automations and reconcile platform status';

    public function handle(PlatformEngine $engine): int
    {
        $engine->tick();
        $this->info('Platform scheduler completed.');

        return self::SUCCESS;
    }
}
