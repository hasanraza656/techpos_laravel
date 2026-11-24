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
        $schedule->command('techpos:expire-subscriptions')->hourly()->withoutOverlapping();
        // Daily encrypted backup once a BACKUP_KEY is configured (see config/backup.php).
        if (config('backup.key')) {
            $schedule->command('techpos:backup')->dailyAt(config('backup.time', '02:00'))->withoutOverlapping(120);
        }
        // $schedule->command('inspire')->hourly();
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
