<?php

namespace App\Console\Commands;

use App\Health\SchedulerControl;
use App\Health\SchedulerGuard;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Command;

/**
 * Cron's way in to the same watchdog the dashboard uses.
 *
 * Deliberately not on the schedule itself — a watchdog that only runs when the
 * scheduler is alive cannot restart a scheduler that isn't. Put this in system
 * cron (`* * * * * php artisan scheduler:guard`) and the box recovers on its own
 * with nobody watching a page. Cheap enough to run every minute: on a healthy
 * box it is one pgrep.
 */
class SchedulerGuardCommand extends Command
{
    protected $signature = 'scheduler:guard';

    protected $description = 'Start the scheduler if it has stopped';

    public function handle(SchedulerGuard $guard, SchedulerControl $scheduler): int
    {
        if ($guard->ensureRunning('checked by scheduler:guard')) {
            $this->warn('Scheduler was stopped — started it.');

            return self::SUCCESS;
        }

        if (ScheduledTaskRun::heartbeatIsFresh()) {
            $this->info('Jobs are running on schedule — nothing to do.');

            return self::SUCCESS;
        }

        $this->info(match ($scheduler->processState()) {
            SchedulerControl::RUNNING => 'Scheduler is running, but no job has started recently — something it launched may be stuck.',
            SchedulerControl::STOPPED => 'Scheduler is stopped, but was not started (auto-start is off, or another attempt was made moments ago).',
            default => 'Could not tell whether the scheduler is running, so nothing was done.',
        });

        return self::SUCCESS;
    }
}
