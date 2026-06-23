<?php

namespace Tests\Feature;

use App\Health\SystemHealth;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function check(string $key): array
    {
        return collect(SystemHealth::checks())->firstWhere('key', $key);
    }

    public function test_database_check_is_ok(): void
    {
        $this->assertSame('ok', $this->check('database')['status']);
    }

    public function test_scheduler_ok_when_recently_run(): void
    {
        ActivityLog::record('attendance.sync', 'ran');

        $this->assertSame('ok', $this->check('scheduler')['status']);
    }

    public function test_scheduler_fails_when_stale(): void
    {
        $log = ActivityLog::record('attendance.sync', 'ran');
        $log->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $this->assertSame('fail', $this->check('scheduler')['status']);
    }

    public function test_scheduler_warns_when_never_run(): void
    {
        $this->assertSame('warn', $this->check('scheduler')['status']);
    }

    public function test_roster_warns_when_empty(): void
    {
        $this->assertSame('warn', $this->check('roster')['status']);
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

    public function test_start_scheduler_launches_it_when_stopped(): void
    {
        $fake = new class extends \App\Health\SchedulerControl
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
        $this->app->instance(\App\Health\SchedulerControl::class, $fake);

        $this->post(route('scheduler.start'))->assertRedirect(route('monitoring'));

        $this->assertTrue($fake->started);
        $this->assertDatabaseHas('activity_log', ['event' => 'scheduler.start']);
    }

    public function test_start_scheduler_is_a_noop_when_already_running(): void
    {
        $fake = new class extends \App\Health\SchedulerControl
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
        $this->app->instance(\App\Health\SchedulerControl::class, $fake);

        $this->post(route('scheduler.start'))->assertRedirect(route('monitoring'));

        $this->assertFalse($fake->started);
    }

    public function test_healthz_returns_json_status_and_checks(): void
    {
        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonStructure(['status', 'checks' => [['key', 'label', 'status', 'detail']]]);
    }
}
