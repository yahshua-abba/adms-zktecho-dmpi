<?php

namespace Tests\Feature;

use App\Console\Kernel;
use App\Health\SchedulerControl;
use App\Health\SchedulerGuard;
use App\Models\ScheduledTaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The watchdog that restarts a stopped scheduler.
 *
 * Everything here runs against a fake SchedulerControl — the real one shells out
 * to pgrep and would launch an actual long-running process on whatever machine
 * is running the suite.
 */
class SchedulerGuardTest extends TestCase
{
    use RefreshDatabase;

    private function control(string $state): object
    {
        $fake = new class extends SchedulerControl
        {
            public string $state = SchedulerControl::STOPPED;

            public int $starts = 0;

            public function processState(): string
            {
                return $this->state;
            }

            public function start(): void
            {
                $this->starts++;
            }
        };
        $fake->state = $state;

        $this->app->instance(SchedulerControl::class, $fake);
        config(['adms.scheduler.autostart' => true]);

        return $fake;
    }

    private function guard(): SchedulerGuard
    {
        return $this->app->make(SchedulerGuard::class);
    }

    public function test_it_starts_a_stopped_scheduler(): void
    {
        $control = $this->control(SchedulerControl::STOPPED);

        $this->assertTrue($this->guard()->ensureRunning('a test'));
        $this->assertSame(1, $control->starts);
    }

    public function test_it_leaves_a_running_scheduler_alone(): void
    {
        $control = $this->control(SchedulerControl::RUNNING);

        $this->assertFalse($this->guard()->ensureRunning('a test'));
        $this->assertSame(0, $control->starts);
    }

    /**
     * The costliest mistake this guard could make. Under the cron-driven setup in
     * the deployment notes there is no `schedule:work` process to find, so acting
     * on the process check alone would start one beside cron's — every job then
     * runs twice, punch pushes included.
     */
    public function test_it_does_not_start_a_second_scheduler_beside_a_cron_driven_one(): void
    {
        $control = $this->control(SchedulerControl::STOPPED);
        ScheduledTaskRun::create([
            'command' => 'payroll:sync-attendances',
            'status' => 'succeeded',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->assertFalse($this->guard()->ensureRunning('a test'));
        $this->assertSame(0, $control->starts);
    }

    /**
     * The important restraint. An unknown state means our own instrument failed,
     * not that the scheduler is down — starting a second one alongside a healthy
     * first would double every scheduled job, including the punch push.
     */
    public function test_it_does_nothing_when_it_cannot_tell(): void
    {
        $control = $this->control(SchedulerControl::UNKNOWN);

        $this->assertFalse($this->guard()->ensureRunning('a test'));
        $this->assertSame(0, $control->starts);
    }

    public function test_it_does_nothing_when_auto_start_is_switched_off(): void
    {
        $control = $this->control(SchedulerControl::STOPPED);
        config(['adms.scheduler.autostart' => false]);

        $this->assertFalse($this->guard()->ensureRunning('a test'));
        $this->assertSame(0, $control->starts);
    }

    /**
     * A newly launched scheduler is not visible to pgrep straight away, so
     * without the throttle every request arriving in that window would see
     * "stopped" and start another one — a refreshed dashboard could spawn a dozen.
     */
    public function test_it_does_not_start_a_second_one_while_the_first_is_booting(): void
    {
        $control = $this->control(SchedulerControl::STOPPED);

        $this->guard()->ensureRunning('first');
        $this->guard()->ensureRunning('second');
        $this->guard()->ensureRunning('third');

        $this->assertSame(1, $control->starts);
    }

    public function test_an_automatic_restart_is_recorded_as_a_warning(): void
    {
        $this->control(SchedulerControl::STOPPED);

        $this->guard()->ensureRunning('noticed by a test');

        $this->assertDatabaseHas('activity_log', [
            'event' => 'scheduler.autostart',
            'level' => 'warning',
        ]);
    }

    public function test_the_monitoring_page_restarts_it_and_says_so(): void
    {
        $control = $this->control(SchedulerControl::STOPPED);

        $this->get('/monitoring')
            ->assertOk()
            ->assertSee("The scheduler wasn't running", false);

        $this->assertSame(1, $control->starts);
    }

    public function test_the_monitoring_page_stays_quiet_when_nothing_needed_fixing(): void
    {
        $this->control(SchedulerControl::RUNNING);

        $this->get('/monitoring')
            ->assertOk()
            ->assertDontSee("The scheduler wasn't running", false);
    }

    /** The heartbeat that keeps working after everyone has closed the dashboard. */
    public function test_a_healthz_poll_restarts_it(): void
    {
        $control = $this->control(SchedulerControl::STOPPED);

        $this->getJson('/healthz');

        $this->assertSame(1, $control->starts);
    }

    public function test_the_guard_command_reports_what_it_did(): void
    {
        $control = $this->control(SchedulerControl::STOPPED);

        $this->artisan('scheduler:guard')
            ->expectsOutputToContain('Scheduler was stopped')
            ->assertSuccessful();

        $this->assertSame(1, $control->starts);
    }

    public function test_the_guard_command_is_quiet_on_a_healthy_box(): void
    {
        $control = $this->control(SchedulerControl::RUNNING);

        $this->artisan('scheduler:guard')
            ->expectsOutputToContain('no job has started recently')
            ->assertSuccessful();

        $this->assertSame(0, $control->starts);
    }

    /**
     * A watchdog on the schedule is dead exactly when it is needed, so this
     * command must stay off the schedule and be driven from outside.
     */
    public function test_the_guard_is_not_itself_a_scheduled_job(): void
    {
        $this->assertArrayNotHasKey('scheduler:guard', Kernel::CADENCE);
    }
}
