<?php

namespace App\Health;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledTask;

/**
 * Writes one `scheduled_task_runs` row per scheduled job run, driven by
 * Laravel's own scheduler events.
 *
 * Watching from the outside is the point. Each command already logs its own
 * result, but that only ever records the failures a command survives long enough
 * to describe — and the two outages that actually hurt here (a job that hangs,
 * and a job blocked because the previous one is still hanging) both leave the
 * command's own logging untouched. These events fire regardless.
 *
 * Every handler is wrapped: a monitoring table that can break the thing it
 * monitors is worse than no table. A failed write costs one missing row.
 */
class ScheduledTaskRecorder
{
    /**
     * Row ids for the runs currently in flight, keyed by task object.
     *
     * `schedule:run` works through the due tasks one at a time in a single
     * process, so this never holds more than a handful, and the process exits
     * at the end of the minute. Keying on the object rather than the command
     * name keeps two schedule entries for the same command apart.
     *
     * @var array<int, int>
     */
    private array $open = [];

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->guard(function () use ($event) {
            $run = ScheduledTaskRun::create([
                'command' => $this->commandName($event->task),
                'status' => 'running',
                'started_at' => now(),
            ]);

            $this->open[spl_object_id($event->task)] = $run->id;
        });
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->guard(function () use ($event) {
            // A null exit code after a completed run means the job never actually
            // ran: `Event::run()` returns early — before touching the exit code —
            // when it finds the previous run still holding the mutex. This is the
            // narrow race where the previous run grabbed the mutex between the
            // filter check and the launch (the ordinary overlap arrives as a
            // skip); Laravel reports it as a normal finish either way, so without
            // this it would be filed as a success.
            $blocked = $event->task->exitCode === null;

            $this->close($event->task, [
                'status' => $blocked ? 'overlapping' : ($event->task->exitCode === 0 ? 'succeeded' : 'failed'),
                'exit_code' => $event->task->exitCode,
                'runtime_ms' => (int) round($event->runtime * 1000),
                'output' => $blocked ? null : $this->tailOutput($event->task),
                'error' => $blocked
                    ? 'Skipped: the previous run of this job was still going.'
                    : null,
            ]);
        });
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->guard(function () use ($event) {
            $this->close($event->task, [
                'status' => 'failed',
                'exit_code' => $event->task->exitCode,
                'output' => $this->tailOutput($event->task),
                'error' => $this->trim($event->exception->getMessage()),
            ]);
        });
    }

    /**
     * A task a filter turned away. No `starting` event fires for these, so this
     * writes the whole row itself.
     *
     * This is where an overlap actually lands. `withoutOverlapping()` is
     * implemented as a `skip` filter that asks whether the mutex exists, so a job
     * blocked by its own previous run is reported through the ordinary "a filter
     * said no" event — indistinguishable, from the outside, from a job that was
     * never meant to run this minute. Re-asking the mutex is what tells them
     * apart, and the difference matters: one is routine, the other means a job has
     * been hanging since the run that still holds it.
     */
    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->guard(function () use ($event) {
            $blocked = $this->blockedByItsOwnPreviousRun($event->task);

            ScheduledTaskRun::create([
                'command' => $this->commandName($event->task),
                'status' => $blocked ? 'overlapping' : 'skipped',
                'started_at' => now(),
                'finished_at' => now(),
                'error' => $blocked
                    ? 'Skipped: the previous run of this job was still going.'
                    : 'Skipped: a condition on the schedule said not to run.',
            ]);
        });
    }

    private function blockedByItsOwnPreviousRun(ScheduledTask $task): bool
    {
        if (! $task->withoutOverlapping || $task->mutex === null) {
            return false;
        }

        try {
            return $task->mutex->exists($task);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Closures rather than `[self::class, 'method']` strings on purpose: a string
     * listener is resolved from the container afresh for every event, which would
     * hand each handler its own empty `$open` map and leave every run stuck at
     * "running". These close over the one instance the subscriber was built with.
     */
    public function subscribe($events): void
    {
        $events->listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $e) => $this->starting($e));
        $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $e) => $this->finished($e));
        $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => $this->failed($e));
        $events->listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $e) => $this->skipped($e));
    }

    /** Finish the open row for this task, filling in a runtime if none was given. */
    private function close(ScheduledTask $task, array $attributes): void
    {
        $id = $this->open[spl_object_id($task)] ?? null;
        $run = $id === null ? null : ScheduledTaskRun::find($id);

        if ($run === null) {
            // No opening row — a listener that failed, or an event order we did not
            // expect. Record the outcome standalone rather than losing it.
            ScheduledTaskRun::create(array_merge([
                'command' => $this->commandName($task),
                'started_at' => now(),
                'finished_at' => now(),
            ], $attributes));

            return;
        }

        unset($this->open[spl_object_id($task)]);

        $attributes['finished_at'] = now();
        $attributes['runtime_ms'] ??= $run->started_at?->diffInMilliseconds(now());

        $run->forceFill($attributes)->save();
    }

    /**
     * The artisan command behind a scheduled entry.
     *
     * `$task->command` is the full shell line Laravel will run — the PHP binary,
     * the artisan path, then the command and its arguments — so the readable
     * part has to be dug out. Closures have no command at all and are named by
     * their description instead.
     */
    private function commandName(ScheduledTask $task): string
    {
        $command = (string) $task->command;

        if ($command === '') {
            return $task->description ?: 'closure';
        }

        // Everything after "artisan", with the surrounding quotes ProcessUtils adds.
        if (preg_match("/artisan['\"]?\s+(.*)$/", $command, $matches) === 1) {
            return trim(str_replace(["'", '"'], '', $matches[1]));
        }

        return trim($command);
    }

    /**
     * The tail of whatever the job printed.
     *
     * Only jobs registered through Kernel's helper redirect their output to a
     * file; anything else still points at the platform's null device, which is
     * either unreadable or empty. Both cases end up as no output rather than as
     * an error.
     */
    private function tailOutput(ScheduledTask $task): ?string
    {
        $path = (string) $task->output;

        if ($path === '' || $path === $task->getDefaultOutput() || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return $this->trim((string) file_get_contents($path));
    }

    private function trim(string $text): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return $text === '' ? null : mb_substr($text, 0, 1000);
    }

    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable) {
            // Monitoring must never take down what it monitors.
        }
    }
}
