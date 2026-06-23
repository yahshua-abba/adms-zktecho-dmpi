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
        // Keep the device-PIN -> payroll-id map fresh.
        $schedule->command('payroll:sync-roster')->hourly()->withoutOverlapping();

        // Push new punches to payroll. Each run drains the full backlog, so a
        // tight cadence keeps latency low without risking pile-ups.
        $schedule->command('payroll:sync-attendances')->everyMinute()->withoutOverlapping();

        // Pull device list + employee-device assignments from DMPI.
        $schedule->command('payroll:sync-devices')->hourly()->withoutOverlapping();

        // Queue enrollment commands so device user lists match payroll assignments.
        $schedule->command('payroll:reconcile-enrollments')->everyFifteenMinutes()->withoutOverlapping();

        // Age out raw diagnostic logs so storage stays bounded.
        $schedule->command('logs:prune')->dailyAt('02:00')->withoutOverlapping();
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
