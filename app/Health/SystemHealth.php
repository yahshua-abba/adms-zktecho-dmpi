<?php

namespace App\Health;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\EmployeeMap;
use App\Models\ScheduledTaskRun;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Server health checks for the monitoring page. Each check returns:
 *   ['key','label','status' => ok|warn|fail,'detail','link' => ?url]
 * The link points to the page where that check's underlying data lives.
 */
class SystemHealth
{
    /** @return array<int, array{key:string,label:string,status:string,detail:string,link:?string}> */
    public static function checks(): array
    {
        return [
            self::database(),
            self::schedulerCheck(),
            self::payrollConfig(),
            self::dmpiReachable(),
            self::syncBacklog(),
            self::roster(),
            self::assignments(),
            self::devices(),
            self::recentErrors(),
        ];
    }

    /** Worst status across all checks: fail > warn > ok. */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        if (in_array('fail', $statuses, true)) {
            return 'fail';
        }

        return in_array('warn', $statuses, true) ? 'warn' : 'ok';
    }

    private static function check(string $key, string $label, string $status, string $detail, ?string $link = null): array
    {
        return compact('key', 'label', 'status', 'detail', 'link');
    }

    private static function database(): array
    {
        try {
            DB::select('select 1');

            return self::check('database', 'Database', 'ok', 'Connected.');
        } catch (\Throwable $e) {
            return self::check('database', 'Database', 'fail', 'Cannot connect: '.$e->getMessage());
        }
    }

    /**
     * Is the scheduler alive, and is it actually getting work done?
     *
     * Two signals, consulted in that order. Recent job runs come first and settle
     * it on their own: if jobs are running, the schedule is being driven, however
     * it is being driven — which matters because a cron-driven install has no
     * `schedule:work` process for the second signal to find.
     *
     * The process check only speaks up once the jobs have gone quiet, and its job
     * is to say *why*. "Not running" and "running but stuck" want opposite
     * responses: the first wants a restart, the second wants someone to look at
     * what is hanging — and restarting would kill the very run that is stuck. The
     * old check could not tell them apart and offered the restart either way.
     *
     * Public because the Scheduler page shows this same verdict at the top. Two
     * screens each answering "is the scheduler alive?" from their own reading of
     * the same signals is two chances to disagree, and an operator who sees
     * "Running" on one and "Not running" on the other has learned only that
     * neither can be trusted.
     */
    public static function schedulerCheck(): array
    {
        $link = route('scheduler.log');
        $last = ScheduledTaskRun::lastRun();

        $since = $last?->started_at ?? $last?->created_at;
        $ago = $since?->diffForHumans();

        if (ScheduledTaskRun::heartbeatIsFresh()) {
            return self::check('scheduler', 'Scheduler', 'ok', "Running — last job {$ago}.", $link);
        }

        $state = app(SchedulerControl::class)->processState();

        if ($state === SchedulerControl::STOPPED) {
            return self::check('scheduler', 'Scheduler', 'fail', $since === null
                ? 'Not running, and no job has ever run. Press Start scheduler.'
                : "Not running — last job {$ago}. Press Start scheduler.", $link);
        }

        if ($state === SchedulerControl::RUNNING) {
            // A scheduler started moments ago is alive with a stale heartbeat, which
            // is the same shape as a wedged one — and every automatic restart lands
            // in exactly that state. Calling it "stuck" would have the dashboard
            // report a fault it had just finished repairing, every single time.
            if (self::startedRecently()) {
                return self::check('scheduler', 'Scheduler', 'warn', 'Just (re)started — the first job runs within a minute.', $link);
            }

            return $since === null
                ? self::check('scheduler', 'Scheduler', 'warn', 'Running, but no job has ever run.', $link)
                // The process is alive and we can prove it, so this is not a
                // stopped scheduler; something it launches is refusing to finish.
                : self::check('scheduler', 'Scheduler', 'fail', "Running, but no job has started since {$ago} — a job is stuck.", $link);
        }

        // UNKNOWN: we could not ask the operating system, so the heartbeat is all
        // we have. Same answer this check always gave before the process test
        // existed — never better than the evidence available.
        return $since === null
            ? self::check('scheduler', 'Scheduler', 'warn', 'No job has run yet — is the scheduler started?', $link)
            : self::check('scheduler', 'Scheduler', 'fail', "Last job was {$ago} — the scheduler may be stopped.", $link);
    }

    /**
     * Was the scheduler started in the last couple of minutes?
     *
     * Long enough to cover the gap before the every-minute job first fires, short
     * enough that a scheduler which starts and then does nothing still gets called
     * out rather than being excused forever.
     */
    private static function startedRecently(): bool
    {
        $start = ActivityLog::whereIn('event', ['scheduler.start', 'scheduler.autostart'])
            ->latest('id')
            ->first();

        return $start !== null && now()->diffInSeconds($start->created_at) <= 120;
    }

    private static function payrollConfig(): array
    {
        $urlError = self::payrollBaseUrlError(config('payroll.base_url'));
        if ($urlError !== null) {
            return self::check('payroll_config', 'Payroll credentials', 'warn', $urlError, route('help'));
        }

        $set = config('payroll.username') && config('payroll.password');

        return $set
            ? self::check('payroll_config', 'Payroll credentials', 'ok', 'Configured.', route('help'))
            : self::check('payroll_config', 'Payroll credentials', 'warn', 'PAYROLL_* not fully set in .env.', route('help'));
    }

    private static function dmpiReachable(): array
    {
        $url = trim((string) config('payroll.base_url'));
        $urlError = self::payrollBaseUrlError($url);
        if ($urlError !== null) {
            return self::check('dmpi', 'DMPI reachable', 'warn', $urlError, route('help'));
        }

        try {
            $response = Http::connectTimeout(5)->timeout(8)->get($url);

            return self::check('dmpi', 'DMPI reachable', 'ok', "Responded (HTTP {$response->status()}).");
        } catch (\Throwable $e) {
            return self::check('dmpi', 'DMPI reachable', 'fail', 'Unreachable: '.$e->getMessage());
        }
    }

    private static function payrollBaseUrlError(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return 'No PAYROLL_URL set.';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'PAYROLL_URL must include http:// or https://.';
        }

        if (! parse_url($url, PHP_URL_HOST)) {
            return 'PAYROLL_URL must be a full DMPI URL.';
        }

        return null;
    }

    private static function syncBacklog(): array
    {
        $pending = Attendance::where('is_sync', false)->whereNull('sync_error')->count();
        $failed = Attendance::where('is_sync', false)->whereNotNull('sync_error')->count();
        $status = ($failed > 0 || $pending > 500) ? 'warn' : 'ok';
        $link = route('devices.Attendance', ['sync' => $failed > 0 ? 'failed' : 'pending']);

        return self::check('sync_backlog', 'Sync backlog', $status, "{$pending} pending, {$failed} failed.", $link);
    }

    /**
     * How stale a payroll download may get before it is worth saying so. Both
     * pulls are scheduled hourly, so three hours means several runs in a row were
     * missed — not one slow afternoon — and a full day means nobody has looked
     * for a shift change since yesterday.
     */
    private const PULL_WARN_HOURS = 3;

    private const PULL_FAIL_HOURS = 24;

    private static function roster(): array
    {
        $count = EmployeeMap::count();
        $link = route('employees.index');

        if ($count === 0) {
            return self::check('roster', 'Employee roster', 'warn', 'No employees mapped — run the roster sync.', $link);
        }

        return self::freshness('roster', 'Employee roster', "{$count} employees mapped", ['employees', 'all'], $link);
    }

    /**
     * Who payroll says belongs on which clock.
     *
     * Separate from the "Devices online" card, which answers a different question
     * — that one is about machines reaching this server, this one is about how
     * long ago we last asked payroll who may use them. A stale answer here is how
     * a leaver keeps badging in for a month.
     */
    private static function assignments(): array
    {
        $count = DeviceAssignment::count();
        $link = route('devices.index');

        if ($count === 0) {
            return self::check('assignments', 'Device assignments', 'warn', 'None downloaded yet — use Download assignments on Devices.', $link);
        }

        return self::freshness(
            'assignments',
            'Device assignments',
            "{$count} assignment(s)",
            ['assignments', 'device-info', 'all'],
            $link,
        );
    }

    /**
     * Turn "when did this last download successfully?" into a status.
     *
     * Counting rows only ever proved that a download happened *once*. A roster
     * pulled in March and never again looked exactly as healthy as one pulled
     * this hour, which made the card useless for the failure it most needed to
     * catch — the pull quietly stopping while its data sat there looking fine.
     *
     * @param  array<int, string>  $parts  which SyncRun parts count as covering this data
     */
    private static function freshness(string $key, string $label, string $summary, array $parts, string $link): array
    {
        $last = SyncRun::lastSuccessful($parts);

        if ($last === null) {
            // Data with no successful download behind it: pre-dating this table, or
            // loaded some other way. Worth flagging, not worth calling an outage.
            return self::check($key, $label, 'warn', "{$summary}, but no successful download is on record.", $link);
        }

        $at = $last->finished_at ?? $last->created_at;
        $hours = now()->diffInHours($at);
        $ago = $at->diffForHumans();

        $status = match (true) {
            $hours >= self::PULL_FAIL_HOURS => 'fail',
            $hours >= self::PULL_WARN_HOURS => 'warn',
            default => 'ok',
        };

        return self::check($key, $label, $status, $status === 'ok'
            ? "{$summary}, last downloaded {$ago}."
            : "{$summary}, but the last successful download was {$ago}.", $link);
    }

    private static function devices(): array
    {
        $all = Device::all();
        $online = $all->filter->isOnline()->count();
        $total = $all->count();
        $status = ($total > 0 && $online === 0) ? 'warn' : 'ok';

        return self::check('devices', 'Devices online', $status, "{$online} of {$total} online.", route('devices.index'));
    }

    private static function recentErrors(): array
    {
        $count = ActivityLog::where('level', 'error')->where('created_at', '>=', now()->subDay())->count();
        $link = route('activity.index', ['level' => 'error']);

        return $count > 0
            ? self::check('errors', 'Recent errors', 'warn', "{$count} error(s) in the last 24h — see Server Activity.", $link)
            : self::check('errors', 'Recent errors', 'ok', 'No errors in the last 24h.', $link);
    }
}
