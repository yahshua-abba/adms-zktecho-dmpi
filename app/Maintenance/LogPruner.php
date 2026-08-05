<?php

namespace App\Maintenance;

use Illuminate\Support\Facades\DB;

/**
 * Deletes log rows older than a retention window.
 *
 * device_log/finger_log are diagnostic and grow without bound (a device_log
 * row roughly every 30s per device from handshakes alone), so they are aged
 * out. payroll_calls, sync_runs, scheduled_task_runs and error_log are the same
 * kind of thing for the outbound, scheduled and ingest sides — a row per request
 * to DMPI, per download, per scheduled job run, per rejected payload — and would
 * otherwise grow forever too. Attendance records are NOT pruned; those are the
 * data of record.
 *
 * Two windows, not one. The short one ages out volume; the long one keeps the
 * handful of rows that answer "when did this start going wrong?", a question
 * that is usually asked long after the noise around it stopped being useful.
 */
class LogPruner
{
    /**
     * @param  int  $days  how long to keep routine rows
     * @param  int|null  $errorDays  how long to keep warnings and errors; defaults to
     *                               config, and is floored at $days so a shorter
     *                               setting can never delete the interesting rows
     *                               before the routine ones around them
     * @return array<string, int> rows deleted per table
     */
    public static function prune(int $days, ?int $errorDays = null): array
    {
        $cutoff = now()->subDays($days);
        $errorCutoff = now()->subDays(max($days, $errorDays ?? (int) config('adms.error_retention_days')));

        return [
            'device_log' => DB::table('device_log')->where('created_at', '<', $cutoff)->delete(),
            'finger_log' => DB::table('finger_log')->where('created_at', '<', $cutoff)->delete(),
            'error_log' => DB::table('error_log')->where('created_at', '<', $errorCutoff)->delete(),
            'payroll_calls' => DB::table('payroll_calls')->where('created_at', '<', $cutoff)->delete(),
            // Only finished runs: a long download started before the cutoff is still
            // the thing the dashboard is currently showing.
            'sync_runs' => DB::table('sync_runs')
                ->where('created_at', '<', $cutoff)
                ->where('status', '!=', 'running')
                ->delete(),
            // The fastest-growing table here: one row per scheduled job run, and the
            // punch push alone runs 1,440 times a day. Same carve-out as sync_runs —
            // an unfinished row is evidence of a job that hung, which is exactly what
            // someone reading this log is looking for.
            'scheduled_task_runs' => DB::table('scheduled_task_runs')
                ->where('created_at', '<', $cutoff)
                ->where('status', '!=', 'running')
                ->delete(),
            'activity_log' => self::pruneActivityLog($cutoff, $errorCutoff),
            'device_commands' => self::pruneDeviceCommands($cutoff),
        ];
    }

    /**
     * Server Activity, split by how interesting the row is.
     *
     * Volume and value point in opposite directions here. Nearly every row is the
     * every-minute punch push saying "0 synced, 0 failed" — 1,440 a day, worth
     * nothing a week later. The warnings and errors are a few a day at most, and
     * they are the entire reason anyone opens this page: "the roster pull has been
     * failing — since when?" is a question about months, not days. Ageing both out
     * together would mean either drowning in idle rows or throwing away the audit
     * trail, so they get separate windows.
     */
    private static function pruneActivityLog(\DateTimeInterface $cutoff, \DateTimeInterface $errorCutoff): int
    {
        $routine = DB::table('activity_log')
            ->where('created_at', '<', $cutoff)
            ->whereNotIn('level', ['warning', 'error'])
            ->delete();

        return $routine + DB::table('activity_log')
            ->where('created_at', '<', $errorCutoff)
            ->whereIn('level', ['warning', 'error'])
            ->delete();
    }

    /**
     * Enrollment commands the devices have finished with.
     *
     * `pending` and `sent` are deliberately untouched, whatever their age.
     * A pending row is work that has not reached its device yet, and the devices
     * this waits on are exactly the ones that go offline for weeks — pruning by
     * age would quietly cancel the enrollment changes for every reader that had
     * been unplugged the longest. `sent` is equally unsafe: the device has the
     * command but has not reported back, so the row is the only record that it is
     * outstanding.
     */
    private static function pruneDeviceCommands(\DateTimeInterface $cutoff): int
    {
        return DB::table('device_commands')
            ->where('created_at', '<', $cutoff)
            ->whereIn('status', ['done', 'failed'])
            ->delete();
    }
}
