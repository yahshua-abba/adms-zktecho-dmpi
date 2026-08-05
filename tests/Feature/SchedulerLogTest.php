<?php

namespace Tests\Feature;

use App\Console\Kernel;
use App\Health\SchedulerControl;
use App\Health\SystemHealth;
use App\Maintenance\LogPruner;
use App\Models\ActivityLog;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulerLogTest extends TestCase
{
    use RefreshDatabase;

    private function aRun(array $attributes = []): ScheduledTaskRun
    {
        return ScheduledTaskRun::create(array_merge([
            'command' => 'payroll:sync-attendances',
            'status' => 'succeeded',
            'runtime_ms' => 1200,
            'started_at' => now(),
            'finished_at' => now(),
        ], $attributes));
    }

    public function test_the_page_lists_recent_runs(): void
    {
        $this->aRun(['command' => 'payroll:sync-roster', 'output' => 'Mapped 9000 employees.']);

        $this->get(route('scheduler.log'))
            ->assertOk()
            ->assertSee('Download employees')
            ->assertSee('Mapped 9000 employees.');
    }

    public function test_the_page_is_behind_the_login(): void
    {
        $this->app['session']->flush();

        $this->withSession([])->get(route('scheduler.log'))->assertRedirect(route('login'));
    }

    public function test_it_explains_itself_when_nothing_has_run(): void
    {
        $this->get(route('scheduler.log'))
            ->assertOk()
            ->assertSee('Nothing recorded yet.');
    }

    public function test_runs_can_be_filtered_by_job(): void
    {
        $this->aRun(['command' => 'payroll:sync-roster', 'output' => 'roster ran']);
        $this->aRun(['command' => 'logs:prune', 'output' => 'pruning ran']);

        $this->get(route('scheduler.log', ['command' => 'logs:prune']))
            ->assertOk()
            ->assertSee('pruning ran')
            ->assertDontSee('roster ran');
    }

    public function test_runs_can_be_filtered_by_outcome(): void
    {
        $this->aRun(['error' => 'went fine']);
        $this->aRun(['status' => 'failed', 'error' => 'DMPI refused the login']);

        $this->get(route('scheduler.log', ['status' => 'failed']))
            ->assertOk()
            ->assertSee('DMPI refused the login')
            ->assertDontSee('went fine');
    }

    /**
     * The every-minute job writes ~1,400 rows a day and would bury the hourly
     * ones in a newest-first list, so each job's own latest result is shown
     * separately. A job that has never run must still appear — that absence is
     * the finding.
     */
    public function test_every_scheduled_job_is_listed_even_if_it_has_never_run(): void
    {
        $response = $this->get(route('scheduler.log'))->assertOk();

        foreach (Kernel::CADENCE as $command => $cadence) {
            $response->assertSee($command);
            $response->assertSee($cadence);
        }

        $response->assertSee('never run');
    }

    /**
     * View runs filters the log, which sits a screenful below the button it is
     * on. Without the anchor, clicking it changes something entirely off screen
     * and reads as a dead button.
     */
    public function test_the_view_runs_button_links_to_the_filtered_run_log(): void
    {
        $this->get(route('scheduler.log'))
            ->assertOk()
            ->assertSee(route('scheduler.log', ['command' => 'logs:prune']).'#runs');
    }

    /**
     * ...and the filter dropdowns must land in the same place. With the button
     * jumping to the log and the dropdown reloading to the top of the page, the
     * page appeared to move at random depending on which control you used.
     */
    public function test_changing_a_filter_lands_in_the_same_place_as_the_button(): void
    {
        $this->get(route('scheduler.log'))
            ->assertOk()
            ->assertSee('action="'.route('scheduler.log').'#runs"', false);
    }

    public function test_the_run_log_names_the_job_it_is_filtered_to(): void
    {
        $this->get(route('scheduler.log', ['command' => 'logs:prune']))
            ->assertOk()
            ->assertSee('Delete old log rows only');
    }

    private function processStateIs(string $state): void
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

    public function test_it_shows_whether_the_scheduler_process_is_alive(): void
    {
        $this->processStateIs(SchedulerControl::STOPPED);

        $this->get(route('scheduler.log'))
            ->assertOk()
            ->assertSee('Not running')
            ->assertSee('No scheduler process is running.');
    }

    /**
     * The verdict is one sentence, not three lights the reader has to combine.
     * On a cron-driven box those lights actively contradicted each other: "jobs
     * running: yes" beside "process: not running".
     */
    public function test_a_missing_process_is_not_alarming_when_jobs_are_running(): void
    {
        $this->processStateIs(SchedulerControl::STOPPED);
        $this->aRun();

        $this->get(route('scheduler.log'))
            ->assertOk()
            ->assertSee('normal when cron drives the schedule')
            ->assertDontSee('No scheduler process is running.');
    }

    /**
     * Both screens read the verdict from the same code. Two pages disagreeing
     * about whether the scheduler is alive teaches an operator only that neither
     * can be trusted.
     */
    public function test_the_page_and_the_monitoring_card_give_the_same_verdict(): void
    {
        $this->processStateIs(SchedulerControl::STOPPED);

        $card = collect(SystemHealth::checks())->firstWhere('key', 'scheduler');

        $this->get(route('scheduler.log'))->assertOk()->assertSee($card['detail']);
    }

    public function test_it_shows_recent_starts_and_automatic_restarts(): void
    {
        ActivityLog::record('scheduler.autostart', 'The scheduler was found stopped and restarted automatically.', 'warning');

        $this->get(route('scheduler.log'))
            ->assertOk()
            ->assertSee('automatic')
            ->assertSee('restarted automatically');
    }

    public function test_a_blocked_run_reads_as_still_busy_rather_than_as_a_success(): void
    {
        $this->aRun(['status' => 'overlapping', 'error' => 'Skipped: the previous run of this job was still going.']);

        $this->get(route('scheduler.log'))
            ->assertOk()
            ->assertSee('still busy')
            ->assertSee('still going');
    }

    /**
     * Kernel::CADENCE is a second copy of what schedule() says, kept for the page.
     * This is what stops the two drifting: add a job to one and forget the other
     * and the page silently omits it, which is the exact failure the page exists
     * to prevent.
     */
    public function test_the_cadence_list_matches_the_real_schedule(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->map(fn ($event) => str_contains($event->command ?? '', 'artisan')
                ? trim(str_replace(["'", '"'], '', explode('artisan', $event->command)[1]))
                : null)
            ->filter()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(collect(Kernel::CADENCE)->keys()->sort()->values()->all(), $scheduled);
    }

    /** Every scheduled job must survive its own overlap guard being hit. */
    public function test_every_scheduled_job_refuses_to_overlap_itself(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertTrue(
                $event->withoutOverlapping,
                "{$event->command} may overlap itself; the DMPI reads take longer than their interval."
            );
        }
    }

    public function test_old_runs_are_pruned_but_unfinished_ones_are_kept(): void
    {
        $this->aRun()->forceFill(['created_at' => now()->subDays(60)])->save();
        $this->aRun(['status' => 'running', 'finished_at' => null])->forceFill(['created_at' => now()->subDays(60)])->save();
        $this->aRun();

        LogPruner::prune(30);

        $this->assertSame(2, ScheduledTaskRun::count());
        $this->assertSame(1, ScheduledTaskRun::where('status', 'running')->count());
    }

    public function test_the_sidebar_links_to_the_page(): void
    {
        $this->get('/monitoring')->assertOk()->assertSee(route('scheduler.log'));
    }

    public function test_the_scheduler_health_card_links_to_the_page(): void
    {
        $card = collect(SystemHealth::checks())->firstWhere('key', 'scheduler');

        $this->assertSame(route('scheduler.log'), $card['link']);
    }
}
