<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\File;

class Kernel extends ConsoleKernel
{
    /**
     * Where each job's console output is redirected so the Scheduler log page can
     * show what a run actually printed.
     *
     * Scheduled commands run in their own subprocess, so nothing they print is
     * visible to the process watching them — by default it all goes to the null
     * device. Redirecting to a file per job is the only handle Laravel offers on
     * it, and `sendOutputTo` truncates rather than appends, so each file holds
     * exactly the last run of that job.
     */
    public const OUTPUT_DIR = 'logs/tasks';

    /**
     * How often each scheduled job runs, in words, for the Scheduler page.
     *
     * A second copy of what schedule() already says, which is a drift risk — so
     * SchedulerLogTest asserts the two lists hold exactly the same commands. That
     * buys a schedule() that still reads as prose, and a page that can show "every
     * hour" next to a job that has not run for six.
     */
    public const CADENCE = [
        'payroll:sync-roster' => 'every hour',
        'payroll:sync-attendances' => 'every minute',
        'payroll:sync-devices' => 'every hour',
        'payroll:reconcile-enrollments' => 'every 15 minutes',
        'logs:prune' => 'daily at 02:00',
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Must exist before any job runs: the output redirect is part of the shell
        // line Laravel builds, so a missing directory doesn't cost us a log file —
        // it stops the command running at all.
        File::ensureDirectoryExists(storage_path(self::OUTPUT_DIR));

        // Keep the device-PIN -> payroll-id map fresh.
        $this->job($schedule, 'payroll:sync-roster')->hourly();

        // Push new punches to payroll. Each run drains the full backlog, so a
        // tight cadence keeps latency low without risking pile-ups.
        $this->job($schedule, 'payroll:sync-attendances')->everyMinute();

        // Pull device list + employee-device assignments from DMPI.
        $this->job($schedule, 'payroll:sync-devices')->hourly();

        // Queue enrollment commands so device user lists match payroll assignments.
        $this->job($schedule, 'payroll:reconcile-enrollments')->everyFifteenMinutes();

        // Age out raw diagnostic logs so storage stays bounded.
        $this->job($schedule, 'logs:prune')->dailyAt('02:00');
    }

    /**
     * A scheduled job with this app's standing rules applied: never overlap
     * itself, and keep its output where the dashboard can read it.
     *
     * `withoutOverlapping()` on every job is deliberate — the DMPI reads can take
     * ten minutes, far longer than their own interval. It also means a hung job
     * shows up as a run of `overlapping` rows in the Scheduler log rather than as
     * silence.
     */
    private function job(Schedule $schedule, string $command): Event
    {
        return $schedule->command($command)
            ->withoutOverlapping()
            ->sendOutputTo(self::outputPathFor($command));
    }

    /** Output file for one scheduled command. Shared with the recorder's reader. */
    public static function outputPathFor(string $command): string
    {
        return storage_path(self::OUTPUT_DIR.'/'.str_replace(':', '-', $command).'.log');
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
