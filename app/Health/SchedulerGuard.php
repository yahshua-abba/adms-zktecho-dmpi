<?php

namespace App\Health;

use App\Models\ActivityLog;
use App\Models\ScheduledTaskRun;
use Illuminate\Support\Facades\Cache;

/**
 * Restarts the scheduler when it is found stopped.
 *
 * The scheduler is a long-running process started by hand and killed by every
 * container restart, so the standing failure mode is: the box reboots at 2am,
 * every automatic sync stops, and nothing resumes until somebody opens the
 * dashboard the next morning and clicks a button. Monitoring made that visible;
 * this makes it self-correcting.
 *
 * There is a chicken-and-egg problem with the obvious fix. A watchdog *on the
 * schedule* is dead exactly when it is needed, so this is driven by things that
 * are alive by definition when they run: an operator loading the monitoring
 * page, an uptime monitor polling /healthz, or cron calling `scheduler:guard`.
 * Between them the scheduler is rarely down for longer than the gap between two
 * health polls.
 */
class SchedulerGuard
{
    private const LOCK = 'scheduler.guard';

    public function __construct(private readonly SchedulerControl $scheduler) {}

    /**
     * Start the scheduler if it is definitely stopped. Returns whether it tried.
     *
     * Only ever acts on a definite STOPPED. An UNKNOWN state means our own
     * instrument failed — `exec()` disabled, no `pgrep` — and starting a second
     * scheduler alongside a healthy one would double every scheduled job,
     * including the punch push. When we cannot tell, we do nothing and let the
     * health card say so.
     *
     * @param  string  $trigger  what noticed, for the activity log
     */
    public function ensureRunning(string $trigger): bool
    {
        if (! config('adms.scheduler.autostart')) {
            return false;
        }

        // Jobs running means the schedule is being driven, and it does not matter
        // by what. The deployment notes offer plain cron calling `schedule:run`
        // every minute as an alternative to a long-running `schedule:work`, and
        // under that setup there is no process for pgrep to find — so the process
        // check alone would see "stopped" on a perfectly healthy box and start a
        // scheduler beside cron's, running every job twice, punch pushes included.
        if (ScheduledTaskRun::heartbeatIsFresh()) {
            return false;
        }

        if ($this->scheduler->processState() !== SchedulerControl::STOPPED) {
            return false;
        }

        // Deliberately never released: holding it for its full TTL *is* the
        // throttle. A newly launched scheduler takes a moment to become visible to
        // pgrep, and without this every request arriving in that window would see
        // "stopped" and start another one — a refreshed dashboard could spawn a
        // dozen schedulers, each running every job.
        $lock = Cache::lock(self::LOCK, max(1, (int) config('adms.scheduler.autostart_throttle')));

        if (! $lock->get()) {
            return false;
        }

        $this->scheduler->start();

        // Recorded as a warning, not as good news. An automatic restart is a
        // repaired outage, and a stream of these means something is killing the
        // scheduler — or that starting it is failing silently and this is retrying
        // forever. Either way the operator should see the pattern rather than a
        // quietly self-healing box.
        ActivityLog::record(
            'scheduler.autostart',
            "The scheduler was found stopped ({$trigger}) and restarted automatically.",
            'warning',
        );

        return true;
    }
}
