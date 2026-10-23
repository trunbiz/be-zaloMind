<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('platform:tick')->everyMinute()->withoutOverlapping();
        $schedule->call(function () {
            \Illuminate\Support\Facades\DB::transaction(function () {
                \Illuminate\Support\Facades\DB::table('agent_runtime')->where('id', 1)->lockForUpdate()->first();
                app(\App\Services\AgentEvents::class)->cleanupQr();
            });
        })->name('agent-qr-cleanup')->everyMinute()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
