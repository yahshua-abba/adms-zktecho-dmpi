<?php

namespace App\Console\Commands\Concerns;

use App\Models\ActivityLog;
use App\Models\SyncRun;
use App\Sync\DmpiSyncLauncher;
use App\Sync\PayrollCallRecorder;
use Illuminate\Support\Facades\Cache;

/**
 * Serialises everything that *downloads* from DMPI, and records the run so the
 * dashboard can show what is happening while it happens.
 *
 * The buttons check the lock before launching, but that check alone is not the
 * guard — the launched command exits before anything reads it again, and two
 * presses a second apart both saw it free. Observed live: two full device pulls
 * running at once, each holding its own connection to DMPI's production server.
 * The command holding the lock for its whole run is what actually prevents that;
 * the controller's check only exists to give the operator a straight answer.
 *
 * Deliberately NOT applied to payroll:sync-attendances. That pushes punches out
 * on a different endpoint every minute, and making it queue behind a ten-minute
 * device read would stall the one flow that must not fall behind.
 */
trait HoldsTheDmpiLock
{
    /**
     * Run $work while holding the shared download lock, with a SyncRun tracking it.
     *
     * The lock TTL is a backstop, not a promise: if the process dies mid-run the
     * lock expires rather than wedging every later download. Stopping a run kills
     * the process outright, so the stop action force-releases the lock and closes
     * the run itself — a SIGKILLed process runs no cleanup of its own.
     *
     * @param  string  $part  employees|devices|assignments|all
     * @param  callable(SyncRun):int  $work
     */
    protected function holdingTheDmpiLock(string $part, callable $work): int
    {
        $lock = Cache::lock(DmpiSyncLauncher::LOCK, 1800);

        if (! $lock->get()) {
            $this->warn('A DMPI download is already running — skipping.');
            ActivityLog::record(
                'dmpi.pull',
                'A DMPI download was requested while another was still running, so it was skipped.',
                'warning',
            );

            return self::SUCCESS;
        }

        $run = SyncRun::create([
            'part' => $part,
            'status' => 'running',
            'stage' => 'Starting',
            // Needed so the Stop button has something to kill: the expensive part is
            // one blocking HTTP read, so a cooperative flag would only be noticed
            // after the thing you wanted to interrupt had already finished.
            'pid' => getmypid(),
            'started_at' => now(),
        ]);

        PayrollCallRecorder::attributeTo($run->id);
        $this->releaseOnFatalError($lock, $run);

        try {
            $exit = $work($run);

            if ($run->fresh()?->isRunning()) {
                $run->finish($exit === self::SUCCESS ? 'succeeded' : 'failed');
            }

            return $exit;
        } catch (\Throwable $e) {
            if ($run->fresh()?->isRunning()) {
                $run->finish('failed', $e->getMessage());
            }

            throw $e;
        } finally {
            PayrollCallRecorder::attributeTo(null);
            $lock->release();
        }
    }

    /**
     * Hand the lock back when PHP dies outright.
     *
     * A fatal error — running out of memory decoding DMPI's reply is the one that
     * actually happens — skips `finally` entirely. The lock then stayed held for
     * its full 30-minute TTL and every later download was refused with "already
     * running", which from the dashboard looks exactly like the button doing
     * nothing. Shutdown functions do still run, so this is the only chance to
     * release it and say what went wrong.
     */
    private function releaseOnFatalError(mixed $lock, SyncRun $run): void
    {
        register_shutdown_function(function () use ($lock, $run) {
            $error = error_get_last();

            if ($error === null || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            // We may have died *because* memory ran out; recording why needs some.
            ini_set('memory_limit', '-1');

            try {
                if ($run->fresh()?->isRunning()) {
                    $run->finish('failed', $this->explainFatal($error['message']));
                }

                ActivityLog::record('dmpi.pull', $this->explainFatal($error['message']), 'error');
            } catch (\Throwable) {
                // Never let cleanup bury the original failure.
            }

            $lock->release();
        });
    }

    /** Turn PHP's wording into something an operator can act on. */
    private function explainFatal(string $message): string
    {
        if (str_contains($message, 'Allowed memory size')) {
            return 'The download ran out of memory reading DMPI\'s reply (limit: '
                .config('payroll.memory_limit').'). The response was bigger than the ceiling — '
                .'raise PAYROLL_MEMORY_LIMIT in .env and try again.';
        }

        return 'The download stopped abruptly: '.mb_substr($message, 0, 300);
    }
}
