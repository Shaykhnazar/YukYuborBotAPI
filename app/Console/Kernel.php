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
        // Warm all caches daily at 3 AM
        $schedule->command('cache:warm-all')
                 ->dailyAt('03:00')
                 ->withoutOverlapping()
                 ->runInBackground();

        // Warm route request counts more frequently (every 2 hours during peak hours)
        $schedule->command('cache:warm-routes')
                 ->hourly()
                 ->between('08:00', '22:00')
                 ->withoutOverlapping()
                 ->runInBackground();
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
