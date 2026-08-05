<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One DMPI download, from launch to outcome. See the migration for why this
 * exists and why it stores a pid.
 */
class SyncRun extends Model
{
    protected $fillable = [
        'part', 'status', 'stage', 'done', 'total', 'pid', 'message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'done' => 'integer',
        'total' => 'integer',
        'pid' => 'integer',
    ];

    /** The run currently in flight, if any. */
    public static function current(): ?self
    {
        return static::where('status', 'running')->latest('id')->first();
    }

    /**
     * The most recent download that actually succeeded and covered any of $parts.
     *
     * Failures are excluded on purpose: this answers "how current is the data we
     * are holding?", and a download that failed left the data exactly as stale as
     * it was before. Counting the attempt would let a job failing every hour
     * report the freshest possible data.
     *
     * @param  array<int, string>  $parts
     */
    public static function lastSuccessful(array $parts): ?self
    {
        return static::where('status', 'succeeded')
            ->whereIn('part', $parts)
            ->latest('id')
            ->first();
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Percentage complete, or null when a percentage would be a lie — which is
     * most of a download's life, since waiting on DMPI has no measurable middle.
     */
    public function percent(): ?int
    {
        if (! $this->total || $this->done === null) {
            return null;
        }

        return (int) min(100, round($this->done / max(1, $this->total) * 100));
    }

    public function elapsedSeconds(): int
    {
        $until = $this->finished_at ?? now();

        return max(0, ($this->started_at ?? $this->created_at)->diffInSeconds($until));
    }

    /** Move to a new stage, optionally with a countable total. */
    public function toStage(string $stage, ?int $done = null, ?int $total = null): void
    {
        $this->forceFill([
            'stage' => $stage,
            'done' => $done,
            'total' => $total,
        ])->save();
    }

    /** Update the counter within the current stage. */
    public function advance(int $done): void
    {
        $this->forceFill(['done' => $done])->save();
    }

    public function finish(string $status, ?string $message = null): void
    {
        $this->forceFill([
            'status' => $status,
            'message' => $message,
            'finished_at' => now(),
        ])->save();

        // A request still open when the run ends never came back — the process was
        // killed, or died mid-read. Leaving it "pending" would read as still in
        // flight forever; "abandoned" is the honest description.
        $this->calls()->where('outcome', 'pending')->update([
            'outcome' => 'abandoned',
            'error' => 'The download ended before DMPI answered.',
        ]);
    }

    /** @return HasMany<PayrollCall> */
    public function calls()
    {
        return $this->hasMany(PayrollCall::class);
    }

    /**
     * The run in flight, closing out any whose process has died.
     *
     * A run killed outright (the Stop button, an OOM, a container restart) never
     * gets to write its own ending, so without this the dashboard would show a
     * download running forever.
     */
    public static function liveOrClosed(): ?self
    {
        $run = static::current();

        if ($run === null || $run->processIsAlive()) {
            return $run;
        }

        $run->finish('failed', 'The download process ended without finishing.');

        return null;
    }

    public function processIsAlive(): bool
    {
        if ($this->pid === null) {
            return false;
        }

        // PHP caches stat results, so a second check inside one request would
        // otherwise still report a process we just killed as alive.
        clearstatcache(true, "/proc/{$this->pid}");

        return file_exists("/proc/{$this->pid}");
    }

    /**
     * Guard against pid reuse before signalling anything: the recorded pid may
     * belong to a completely unrelated process by the time Stop is pressed.
     */
    public function looksLikeOurProcess(): bool
    {
        if (! $this->processIsAlive()) {
            return false;
        }

        $cmdline = @file_get_contents("/proc/{$this->pid}/cmdline");

        if ($cmdline === false) {
            return false;
        }

        $cmdline = str_replace("\0", ' ', $cmdline);

        return str_contains($cmdline, 'artisan') && str_contains($cmdline, 'payroll:');
    }

    /** What the operator should be told this run is doing. */
    public function describe(): string
    {
        return match ($this->part) {
            'employees' => 'Downloading employees',
            'devices' => 'Downloading the clock list',
            'assignments' => 'Downloading assignments',
            'device-info' => 'Downloading the clock list and assignments',
            default => 'Downloading everything from DMPI',
        };
    }
}
