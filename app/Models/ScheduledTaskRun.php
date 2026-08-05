<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One run of one scheduled job. See the migration for why this exists and what
 * each status means.
 */
class ScheduledTaskRun extends Model
{
    protected $fillable = [
        'command', 'status', 'exit_code', 'runtime_ms', 'output', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'exit_code' => 'integer',
        'runtime_ms' => 'integer',
    ];

    /**
     * Plain-English names for the jobs, so the log reads as work rather than as
     * artisan syntax. Anything unmapped falls back to the command itself — a new
     * scheduled job should show up in the log immediately, not silently.
     */
    public const LABELS = [
        'payroll:sync-roster' => 'Download employees',
        'payroll:sync-attendances' => 'Push punches to payroll',
        'payroll:sync-devices' => 'Download devices and assignments',
        'payroll:reconcile-enrollments' => 'Update the clocks',
        'payroll:sync-all' => 'Download everything from DMPI',
        'logs:prune' => 'Delete old log rows',
        'scheduler:guard' => 'Check the scheduler is alive',
    ];

    /**
     * How long the schedule may go without starting a job before something is
     * wrong. The busiest job runs every minute, so three minutes is comfortably
     * past a slow one without waiting out a real outage.
     */
    public const HEARTBEAT_SECONDS = 180;

    /**
     * Has *something* run the schedule recently?
     *
     * The authoritative "is this working?" signal, and deliberately blind to how
     * the schedule is being driven. A long-running `schedule:work` is only one of
     * the supported setups; the deployment notes also offer plain cron calling
     * `schedule:run` every minute, and under that setup there is no process for
     * pgrep to find. Judging by the process alone would report a healthy
     * cron-driven box as a dead scheduler — and have the watchdog start a second
     * scheduler beside cron's, running every job twice.
     */
    public static function heartbeatIsFresh(): bool
    {
        $last = static::lastRun();
        $since = $last?->started_at ?? $last?->created_at;

        return $since !== null && now()->diffInSeconds($since) <= self::HEARTBEAT_SECONDS;
    }

    /**
     * The most recent run of anything.
     *
     * This is the scheduler's pulse, and it deliberately counts *failed* runs
     * too: a job that runs every minute and fails every minute proves the
     * scheduler is alive and doing its job of launching things. Treating that as
     * "scheduler stopped" would point the operator at the one thing that isn't
     * broken.
     */
    public static function lastRun(): ?self
    {
        return static::latest('id')->first();
    }

    /** The most recent run of one particular job, whatever its outcome. */
    public static function lastRunOf(string $command): ?self
    {
        return static::where('command', $command)->latest('id')->first();
    }

    public function label(): string
    {
        return self::LABELS[$this->command] ?? $this->command;
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * A run still marked "running" long after it started.
     *
     * There is no third state to distinguish "genuinely still working" from "the
     * process was killed and nobody closed the row", because a killed process
     * runs no cleanup — the same reason SyncRun has to be closed by the Stop
     * button rather than by itself. Age is the only available signal, so the UI
     * says "still running" and lets the timestamp speak.
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /** Human-readable duration, or a dash while there is nothing to measure. */
    public function duration(): string
    {
        if ($this->runtime_ms === null) {
            return '—';
        }

        return $this->runtime_ms < 1000
            ? $this->runtime_ms.' ms'
            : round($this->runtime_ms / 1000, 1).' s';
    }

    /** The one-line "what happened", preferring the failure reason when there is one. */
    public function detail(): string
    {
        return trim((string) ($this->error ?: $this->output)) ?: '—';
    }
}
