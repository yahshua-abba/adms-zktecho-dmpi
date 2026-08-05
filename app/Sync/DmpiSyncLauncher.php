<?php

namespace App\Sync;

use App\Models\SyncRun;
use Illuminate\Support\Facades\Cache;

/**
 * Starts a DMPI pull as a detached process, so the browser never waits on it.
 *
 * The manual sync buttons used to run inline in the web request. Payroll reads
 * are allowed ten minutes each and the app is served by a single-request-at-a-
 * time dev server, so one press could take the whole dashboard offline for
 * minutes — including the login page and /healthz.
 *
 * Detaching mirrors what SchedulerControl already does for `schedule:work`: this
 * box has no queue worker, and the scheduler itself is operator-started and dies
 * on container restart, so adding a second process to keep alive would make
 * things less reliable rather than more.
 *
 * All three buttons share ONE lock. They hit the same payroll server with the
 * same credentials, and DMPI locks an account out after enough failed attempts,
 * so letting them pile up concurrently is the wrong kind of helpful.
 *
 * isRunning() is advisory — it exists to give the operator a straight answer
 * instead of a silent no-op. The authoritative guard is the lock each command
 * holds, so a lost race still can't produce two concurrent syncs.
 */
class DmpiSyncLauncher
{
    /** Shared with the sync commands, which hold this for the duration of a run. */
    public const LOCK = 'dmpi-sync';

    /**
     * What each button runs. Devices and assignments are separate entries even
     * though they come from the SAME DMPI response — read_device_info returns
     * both and takes no parameter to narrow it — because they differ in what
     * they write, not in what they cost.
     */
    public const PARTS = [
        'employees' => 'payroll:sync-roster',
        'devices' => 'payroll:sync-devices --only=devices',
        'assignments' => 'payroll:sync-devices --only=assignments',
    ];

    public function isRunning(): bool
    {
        $lock = Cache::lock(self::LOCK, 1);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        // The lock is held — but by a live download, or by a dead one? A process
        // killed outright, or stopped by a fatal error, cannot hand its lock back,
        // and the lock would then refuse every download for the rest of its
        // 30-minute TTL while the dashboard showed nothing running at all. The run
        // record is the source of truth about liveness, so if nothing is actually
        // running the lock is orphaned and is reclaimed rather than obeyed.
        if (SyncRun::liveOrClosed() === null) {
            Cache::lock(self::LOCK)->forceRelease();

            return false;
        }

        return true;
    }

    /** @param  string  $part  a key of self::PARTS */
    public function start(string $part): void
    {
        $command = self::PARTS[$part] ?? null;

        if ($command === null) {
            throw new \InvalidArgumentException("Unknown DMPI sync part [{$part}].");
        }

        $artisan = escapeshellarg(base_path('artisan'));
        $log = escapeshellarg(storage_path('logs/dmpi-sync.log'));

        // Detached so it outlives the web request that spawned it. The command is
        // from the constant above, never from user input.
        exec("nohup php {$artisan} {$command} >> {$log} 2>&1 &");
    }
}
