<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The recorder listens to Laravel's own scheduler events, so these tests drive
 * those events directly rather than running the scheduler — the point under test
 * is the translation from "what Laravel reports" to "what an operator reads".
 */
class ScheduledTaskRecordingTest extends TestCase
{
    use RefreshDatabase;

    private function task(string $command = 'logs:prune'): ScheduledTask
    {
        return app(Schedule::class)->command($command);
    }

    public function test_a_successful_run_is_recorded_with_its_duration(): void
    {
        $task = $this->task('payroll:sync-attendances');
        event(new ScheduledTaskStarting($task));

        $task->exitCode = 0;
        event(new ScheduledTaskFinished($task, 1.5));

        $run = ScheduledTaskRun::sole();
        $this->assertSame('payroll:sync-attendances', $run->command);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(0, $run->exit_code);
        $this->assertSame(1500, $run->runtime_ms);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_non_zero_exit_code_is_recorded_as_a_failure(): void
    {
        $task = $this->task();
        event(new ScheduledTaskStarting($task));

        $task->exitCode = 1;
        event(new ScheduledTaskFinished($task, 0.2));

        $this->assertSame('failed', ScheduledTaskRun::sole()->status);
    }

    public function test_a_thrown_exception_is_recorded_with_its_message(): void
    {
        $task = $this->task();
        event(new ScheduledTaskStarting($task));

        event(new ScheduledTaskFailed($task, new \RuntimeException('DMPI said no')));

        $run = ScheduledTaskRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('DMPI said no', $run->error);
    }

    /**
     * The one that matters most, and the path a real overlap actually takes:
     * `withoutOverlapping()` is a `skip` filter, so a job blocked by its own
     * previous run arrives as an ordinary "a filter said no". Left undistinguished
     * it reads as routine, when it in fact means the previous run has been hanging
     * since it started.
     */
    public function test_a_job_blocked_by_its_own_previous_run_says_so(): void
    {
        $task = $this->task('payroll:sync-devices')->withoutOverlapping();
        $task->mutex->create($task);

        event(new ScheduledTaskSkipped($task));

        $run = ScheduledTaskRun::sole();
        $this->assertSame('overlapping', $run->status);
        $this->assertStringContainsString('still going', $run->error);
    }

    /** The narrow race where the mutex is taken after the filter check passed. */
    public function test_a_run_that_finishes_without_an_exit_code_is_not_a_success(): void
    {
        $task = $this->task('payroll:sync-devices');
        event(new ScheduledTaskStarting($task));

        $task->exitCode = null;
        event(new ScheduledTaskFinished($task, 0.01));

        $this->assertSame('overlapping', ScheduledTaskRun::sole()->status);
    }

    /**
     * A skip for any *other* reason must not be dressed up as an overlap — that
     * would point an operator at a hung job that does not exist.
     */
    public function test_a_filtered_out_task_is_recorded_without_a_starting_event(): void
    {
        event(new ScheduledTaskSkipped($this->task()));

        $run = ScheduledTaskRun::sole();
        $this->assertSame('skipped', $run->status);
        $this->assertSame('logs:prune', $run->command);
        $this->assertStringContainsString('a condition', $run->error);
    }

    public function test_an_overlap_check_on_a_job_that_allows_overlap_is_not_confused(): void
    {
        event(new ScheduledTaskSkipped($this->task('logs:prune')));

        $this->assertSame('skipped', ScheduledTaskRun::sole()->status);
    }

    /**
     * A run left open — the process was killed between starting and finishing —
     * must stay visibly unfinished rather than being tidied into a success.
     */
    public function test_a_run_that_never_finishes_stays_marked_running(): void
    {
        event(new ScheduledTaskStarting($this->task()));

        $run = ScheduledTaskRun::sole();
        $this->assertTrue($run->isRunning());
        $this->assertNull($run->finished_at);
    }

    public function test_two_runs_of_the_same_job_are_separate_rows(): void
    {
        foreach ([0, 1] as $exit) {
            $task = $this->task();
            event(new ScheduledTaskStarting($task));
            $task->exitCode = $exit;
            event(new ScheduledTaskFinished($task, 0.1));
        }

        $this->assertSame(2, ScheduledTaskRun::count());
        $this->assertSame(['succeeded', 'failed'], ScheduledTaskRun::orderBy('id')->pluck('status')->all());
    }

    /**
     * `$task->command` is the whole shell line — PHP binary, artisan path, then
     * the command — so the readable part has to be extracted. If that breaks, the
     * log fills with absolute paths and the per-job filter stops matching.
     */
    public function test_the_command_name_is_extracted_from_the_full_shell_line(): void
    {
        $task = $this->task('payroll:reconcile-enrollments');
        $this->assertStringContainsString('artisan', $task->command);

        event(new ScheduledTaskStarting($task));

        $this->assertSame('payroll:reconcile-enrollments', ScheduledTaskRun::sole()->command);
    }
}
