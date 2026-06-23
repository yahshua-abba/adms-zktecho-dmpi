<?php

namespace App\Health;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\EmployeeMap;
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
            self::scheduler(),
            self::payrollConfig(),
            self::dmpiReachable(),
            self::syncBacklog(),
            self::roster(),
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

    private static function scheduler(): array
    {
        $link = route('activity.index');
        $last = ActivityLog::where('event', 'attendance.sync')->latest('id')->first();

        if ($last === null) {
            return self::check('scheduler', 'Scheduler', 'warn', 'No sync has run yet — is the scheduler started?', $link);
        }

        $ago = $last->created_at->diffForHumans();
        if (now()->diffInSeconds($last->created_at) <= 180) {
            return self::check('scheduler', 'Scheduler', 'ok', "Running — last push {$ago}.", $link);
        }

        return self::check('scheduler', 'Scheduler', 'fail', "Last push was {$ago} — scheduler may be stopped.", $link);
    }

    private static function payrollConfig(): array
    {
        $set = config('payroll.base_url') && config('payroll.username') && config('payroll.password');

        return $set
            ? self::check('payroll_config', 'Payroll credentials', 'ok', 'Configured.', route('help'))
            : self::check('payroll_config', 'Payroll credentials', 'warn', 'PAYROLL_* not fully set in .env.', route('help'));
    }

    private static function dmpiReachable(): array
    {
        $url = config('payroll.base_url');
        if (! $url) {
            return self::check('dmpi', 'DMPI reachable', 'warn', 'No PAYROLL_URL set.', route('help'));
        }

        try {
            $response = Http::connectTimeout(5)->timeout(8)->get($url);

            return self::check('dmpi', 'DMPI reachable', 'ok', "Responded (HTTP {$response->status()}).");
        } catch (\Throwable $e) {
            return self::check('dmpi', 'DMPI reachable', 'fail', 'Unreachable: '.$e->getMessage());
        }
    }

    private static function syncBacklog(): array
    {
        $pending = Attendance::where('is_sync', false)->whereNull('sync_error')->count();
        $failed = Attendance::where('is_sync', false)->whereNotNull('sync_error')->count();
        $status = ($failed > 0 || $pending > 500) ? 'warn' : 'ok';
        $link = route('devices.Attendance', ['sync' => $failed > 0 ? 'failed' : 'pending']);

        return self::check('sync_backlog', 'Sync backlog', $status, "{$pending} pending, {$failed} failed.", $link);
    }

    private static function roster(): array
    {
        $count = EmployeeMap::count();
        $link = route('employees.index');

        return $count > 0
            ? self::check('roster', 'Employee roster', 'ok', "{$count} employees mapped.", $link)
            : self::check('roster', 'Employee roster', 'warn', 'No employees mapped — run the roster sync.', $link);
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
