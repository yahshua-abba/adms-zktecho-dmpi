<?php

namespace Tests\Feature;

use App\Health\SchedulerControl;
use App\Health\SystemHealth;
use App\Models\ActivityLog;
use App\Models\DeviceAssignment;
use App\Models\EmployeeMap;
use App\Models\ScheduledTaskRun;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function check(string $key): array
    {
        return collect(SystemHealth::checks())->firstWhere('key', $key);
    }

    /** Pin the process check to a known answer; the real one shells out to pgrep. */
    private function schedulerProcessIs(string $state): void
    {
        $fake = new class extends SchedulerControl
        {
            public string $state = SchedulerControl::UNKNOWN;

            public function processState(): string
            {
                return $this->state;
            }
        };
        $fake->state = $state;

        $this->app->instance(SchedulerControl::class, $fake);
    }

    private function jobRanAt(\DateTimeInterface $at): void
    {
        ScheduledTaskRun::create([
            'command' => 'payroll:sync-attendances',
            'status' => 'succeeded',
            'started_at' => $at,
            'finished_at' => $at,
        ]);
    }

    public function test_database_check_is_ok(): void
    {
        $this->assertSame('ok', $this->check('database')['status']);
    }

    public function test_scheduler_ok_when_a_job_ran_moments_ago(): void
    {
        $this->schedulerProcessIs(SchedulerControl::RUNNING);
        $this->jobRanAt(now());

        $this->assertSame('ok', $this->check('scheduler')['status']);
    }

    public function test_scheduler_fails_when_the_process_is_gone(): void
    {
        $this->schedulerProcessIs(SchedulerControl::STOPPED);
        $this->jobRanAt(now()->subMinutes(20));

        $check = $this->check('scheduler');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('Not running', $check['detail']);
    }

    /**
     * The deployment notes offer plain cron calling `schedule:run` every minute
     * instead of a long-running `schedule:work`. There is no process to find in
     * that setup, so judging by the process alone would report a perfectly
     * healthy box as a dead scheduler. Jobs running settles it.
     */
    public function test_a_cron_driven_schedule_is_healthy_with_no_process_to_find(): void
    {
        $this->schedulerProcessIs(SchedulerControl::STOPPED);
        $this->jobRanAt(now());

        $this->assertSame('ok', $this->check('scheduler')['status']);
    }

    /**
     * The distinction the old check could not draw. The process is alive, so this
     * is not something Start scheduler fixes — pressing it would only kill the run
     * that is stuck. The wording has to send the operator somewhere else.
     */
    public function test_scheduler_says_a_job_is_stuck_when_it_is_alive_but_idle(): void
    {
        $this->schedulerProcessIs(SchedulerControl::RUNNING);
        $this->jobRanAt(now()->subMinutes(30));

        $check = $this->check('scheduler');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('stuck', $check['detail']);
        $this->assertStringNotContainsString('Not running', $check['detail']);
    }

    public function test_scheduler_warns_rather_than_fails_just_after_starting(): void
    {
        $this->schedulerProcessIs(SchedulerControl::RUNNING);

        $this->assertSame('warn', $this->check('scheduler')['status']);
    }

    /**
     * A scheduler started moments ago is alive with a stale heartbeat — the same
     * shape as a wedged one, and the state every automatic restart lands in. Left
     * undistinguished, the dashboard reports a fault it has just repaired.
     */
    public function test_a_just_restarted_scheduler_is_not_called_stuck(): void
    {
        $this->schedulerProcessIs(SchedulerControl::RUNNING);
        $this->jobRanAt(now()->subMinutes(30));
        ActivityLog::record('scheduler.autostart', 'restarted', 'warning');

        $check = $this->check('scheduler');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('(re)started', $check['detail']);
    }

    /** ...but a scheduler that starts and then does nothing is not excused forever. */
    public function test_a_scheduler_that_started_long_ago_and_ran_nothing_is_still_stuck(): void
    {
        $this->schedulerProcessIs(SchedulerControl::RUNNING);
        $this->jobRanAt(now()->subMinutes(30));
        ActivityLog::record('scheduler.autostart', 'restarted', 'warning')
            ->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $this->assertSame('fail', $this->check('scheduler')['status']);
    }

    /**
     * When the process check itself is unavailable we fall back to the heartbeat
     * alone — the same answer this card gave before the process test existed.
     * Reporting an outage on the strength of a broken instrument would be worse
     * than reporting less.
     */
    public function test_scheduler_falls_back_to_the_heartbeat_when_it_cannot_inspect_processes(): void
    {
        $this->schedulerProcessIs(SchedulerControl::UNKNOWN);
        $this->jobRanAt(now());
        $this->assertSame('ok', $this->check('scheduler')['status']);

        ScheduledTaskRun::query()->delete();
        $this->jobRanAt(now()->subHour());
        $this->assertSame('fail', $this->check('scheduler')['status']);
    }

    public function test_scheduler_warns_when_nothing_has_ever_run(): void
    {
        $this->schedulerProcessIs(SchedulerControl::UNKNOWN);

        $this->assertSame('warn', $this->check('scheduler')['status']);
    }

    /** A failing job still proves the scheduler is alive and launching things. */
    public function test_a_failing_job_still_counts_as_a_heartbeat(): void
    {
        $this->schedulerProcessIs(SchedulerControl::RUNNING);
        ScheduledTaskRun::create([
            'command' => 'payroll:sync-attendances',
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->assertSame('ok', $this->check('scheduler')['status']);
    }

    public function test_roster_warns_when_empty(): void
    {
        $this->assertSame('warn', $this->check('roster')['status']);
    }

    private function mapAnEmployee(): void
    {
        EmployeeMap::create([
            'device_pin' => '271_1', 'company' => '271', 'chapa' => '1',
            'payroll_employee_id' => 1, 'name' => 'A',
        ]);
    }

    private function downloadSucceeded(string $part, \DateTimeInterface $at): void
    {
        SyncRun::create([
            'part' => $part,
            'status' => 'succeeded',
            'started_at' => $at,
            'finished_at' => $at,
        ])->forceFill(['created_at' => $at])->save();
    }

    public function test_roster_is_ok_when_recently_downloaded(): void
    {
        $this->mapAnEmployee();
        $this->downloadSucceeded('employees', now()->subMinutes(20));

        $this->assertSame('ok', $this->check('roster')['status']);
    }

    /**
     * The failure the old count-the-rows check could not see: data still present,
     * pull long since stopped.
     */
    public function test_roster_fails_when_the_last_download_is_a_day_old(): void
    {
        $this->mapAnEmployee();
        $this->downloadSucceeded('employees', now()->subHours(30));

        $check = $this->check('roster');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('last successful download', $check['detail']);
    }

    public function test_roster_warns_when_the_last_download_is_a_few_hours_old(): void
    {
        $this->mapAnEmployee();
        $this->downloadSucceeded('employees', now()->subHours(5));

        $this->assertSame('warn', $this->check('roster')['status']);
    }

    /** A download that failed left the data exactly as stale as it already was. */
    public function test_a_failed_download_does_not_refresh_the_roster_card(): void
    {
        $this->mapAnEmployee();
        $this->downloadSucceeded('employees', now()->subHours(30));
        SyncRun::create(['part' => 'employees', 'status' => 'failed', 'started_at' => now(), 'finished_at' => now()]);

        $this->assertSame('fail', $this->check('roster')['status']);
    }

    /** A device pull must not vouch for a roster it never touched, and vice versa. */
    public function test_a_device_download_does_not_refresh_the_roster_card(): void
    {
        $this->mapAnEmployee();
        $this->downloadSucceeded('device-info', now());

        $this->assertSame('warn', $this->check('roster')['status']);
        $this->assertStringContainsString('no successful download', $this->check('roster')['detail']);
    }

    public function test_assignments_warn_when_none_downloaded(): void
    {
        $this->assertSame('warn', $this->check('assignments')['status']);
    }

    public function test_assignments_are_ok_after_a_recent_device_pull(): void
    {
        DeviceAssignment::create(['device_code' => 'D1', 'payroll_employee_id' => 1]);
        $this->downloadSucceeded('device-info', now()->subMinutes(10));

        $this->assertSame('ok', $this->check('assignments')['status']);
    }

    public function test_assignments_fail_when_the_last_pull_is_a_day_old(): void
    {
        DeviceAssignment::create(['device_code' => 'D1', 'payroll_employee_id' => 1]);
        $this->downloadSucceeded('assignments', now()->subHours(26));

        $this->assertSame('fail', $this->check('assignments')['status']);
    }

    public function test_payroll_config_warns_when_url_has_no_scheme(): void
    {
        config([
            'payroll.base_url' => 'delmontepayroll.com',
            'payroll.username' => 'svc',
            'payroll.password' => 'secret',
        ]);

        $this->assertSame('warn', $this->check('payroll_config')['status']);
        $this->assertSame('PAYROLL_URL must include http:// or https://.', $this->check('payroll_config')['detail']);
        $this->assertSame('warn', $this->check('dmpi')['status']);
    }

    public function test_data_backed_checks_link_to_their_data(): void
    {
        $this->assertSame(route('devices.Attendance', ['sync' => 'pending']), $this->check('sync_backlog')['link']);
        $this->assertSame(route('employees.index'), $this->check('roster')['link']);
        $this->assertSame(route('devices.index'), $this->check('devices')['link']);
        $this->assertSame(route('activity.index', ['level' => 'error']), $this->check('errors')['link']);
    }

    public function test_monitoring_page_renders_health_checks(): void
    {
        $this->get('/monitoring')->assertOk()->assertSee('System health')->assertSee('Database');
    }

    public function test_monitoring_page_has_a_start_scheduler_button(): void
    {
        $this->get('/monitoring')->assertOk()->assertSee('Start scheduler');
    }

    /**
     * isRunning() shells out to pgrep, which matches whole command lines —
     * including the shell PHP spawns to run the pgrep itself. That made it report
     * "running" on a box where the scheduler had been dead for months, which is
     * the worst possible failure for a liveness indicator: it hid the outage.
     */
    public function test_scheduler_liveness_check_does_not_match_its_own_lookup(): void
    {
        exec('ps -eo args', $processes);
        $real = array_filter(
            $processes,
            fn ($line) => str_contains($line, 'schedule:work') && ! str_contains($line, 'pgrep')
        );

        if ($real !== []) {
            $this->markTestSkipped('A scheduler really is running here, so "not running" cannot be asserted.');
        }

        $this->assertFalse(
            (new SchedulerControl)->isRunning(),
            'no scheduler is running, so this must report false rather than finding its own pgrep'
        );
    }

    /**
     * `schedule:work` spawns `php artisan schedule:run` every minute from a
     * hardcoded *relative* path with no working directory of its own, so it
     * inherits ours. Launched from a web request that is the document root, where
     * there is no `artisan` — the scheduler came up, pgrep found it, the dashboard
     * said "Running", and not one job ever ran.
     */
    public function test_the_scheduler_is_launched_from_the_project_root(): void
    {
        $command = (new SchedulerControl)->launchCommand();

        $this->assertStringStartsWith('cd '.escapeshellarg(base_path()).' &&', $command);
        $this->assertStringContainsString('schedule:work', $command);
    }

    public function test_start_scheduler_launches_it_when_stopped(): void
    {
        $fake = new class extends SchedulerControl
        {
            public bool $started = false;

            public function isRunning(): bool
            {
                return false;
            }

            public function start(): void
            {
                $this->started = true;
            }
        };
        $this->app->instance(SchedulerControl::class, $fake);

        $this->post(route('scheduler.start'))->assertRedirect(route('monitoring'));

        $this->assertTrue($fake->started);
        $this->assertDatabaseHas('activity_log', ['event' => 'scheduler.start']);
    }

    public function test_start_scheduler_is_a_noop_when_already_running(): void
    {
        $fake = new class extends SchedulerControl
        {
            public bool $started = false;

            public function isRunning(): bool
            {
                return true;
            }

            public function start(): void
            {
                $this->started = true;
            }
        };
        $this->app->instance(SchedulerControl::class, $fake);

        $this->post(route('scheduler.start'))->assertRedirect(route('monitoring'));

        $this->assertFalse($fake->started);
    }

    public function test_healthz_returns_json_status_and_checks(): void
    {
        $this->schedulerProcessIs(SchedulerControl::RUNNING);
        $this->jobRanAt(now());

        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonStructure(['status', 'checks' => [['key', 'label', 'status', 'detail']]]);
    }

    /**
     * A stopped scheduler means no punch has reached payroll since it died, which
     * is precisely what an external uptime monitor is polling this to find out.
     * It used to answer 200 because the check could only see a stale heartbeat
     * and would not commit to calling it an outage.
     */
    public function test_healthz_reports_a_stopped_scheduler_as_an_outage(): void
    {
        $this->schedulerProcessIs(SchedulerControl::STOPPED);

        $this->getJson('/healthz')
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail');
    }
}
