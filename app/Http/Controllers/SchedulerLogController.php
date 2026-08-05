<?php

namespace App\Http\Controllers;

use App\Console\Kernel;
use App\Health\SchedulerControl;
use App\Health\SystemHealth;
use App\Models\ActivityLog;
use App\Models\ScheduledTaskRun;
use App\Support\PerPage;
use Illuminate\Http\Request;

/**
 * The Scheduler log: what the background scheduler has been doing, and whether
 * it is doing it now.
 *
 * The Monitoring page answers "is it alive?" in one word. This answers the
 * questions that follow — which job, when, how long it took, and what it said —
 * which is what you need when the one-word answer is "no" and you are trying to
 * work out why. Everything here is read from `scheduled_task_runs`, recorded
 * from the outside by App\Health\ScheduledTaskRecorder, so a job that dies
 * before it can log anything for itself still appears.
 */
class SchedulerLogController extends Controller
{
    public function index(Request $request, SchedulerControl $scheduler)
    {
        $perPage = PerPage::resolve($request->has('per_page') ? (int) $request->query('per_page') : null);

        $command = $request->query('command');
        $status = $request->query('status');

        $runs = ScheduledTaskRun::query()
            ->when($command, fn ($q, $c) => $q->where('command', $c))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        return view('logs.scheduler', [
            'runs' => $runs,
            'command' => $command,
            'status' => $status,
            // The same verdict the Monitoring page shows, from the same code, so the
            // two screens cannot contradict each other.
            'verdict' => SystemHealth::schedulerCheck(),
            'supporting' => $this->supportingFacts($scheduler),
            'jobs' => $this->jobSummary(),
            // Starts and automatic restarts, so a scheduler that keeps dying reads
            // as a pattern rather than as a series of unrelated green ticks.
            'lifecycle' => ActivityLog::whereIn('event', ['scheduler.start', 'scheduler.autostart'])
                ->latest('id')
                ->limit(5)
                ->get(),
            // The filter offers every job that has ever run *plus* every job on the
            // schedule. A job that has never run once is exactly the one worth
            // being able to select — an empty result is the answer.
            'commands' => collect(array_keys(Kernel::CADENCE))
                ->merge(ScheduledTaskRun::query()->distinct()->orderBy('command')->pluck('command'))
                ->unique()
                ->values(),
        ]);
    }

    /**
     * The facts behind the verdict, as one muted line under it.
     *
     * Deliberately secondary. These used to be three equal-sized panels across the
     * top, which made the reader assemble the answer themselves out of "jobs
     * running: yes", "process: not running" and "auto-restart: on" — three lights
     * that only mean something in combination, and that flatly contradict each
     * other on a cron-driven box. The verdict above says what is true; this says
     * how we know.
     *
     * @return array<int, string>
     */
    private function supportingFacts(SchedulerControl $scheduler): array
    {
        $facts = [match ($scheduler->processState()) {
            SchedulerControl::RUNNING => 'A scheduler process is running.',
            SchedulerControl::STOPPED => ScheduledTaskRun::heartbeatIsFresh()
                // Not a fault, and not worth a red light: with cron driving
                // `schedule:run` there is no long-running process to find, and the
                // jobs running is the proof that it does not matter.
                ? 'No long-running process — normal when cron drives the schedule.'
                : 'No scheduler process is running.',
            default => "This server can't inspect processes, so the run log below is the only evidence.",
        }];

        $facts[] = config('adms.scheduler.autostart')
            ? 'If it stops, it starts again on its own.'
            : 'Automatic restart is off — someone has to press Start scheduler.';

        return $facts;
    }

    /**
     * One line per scheduled job: how often it should run, and how it last went.
     *
     * The paginated log below is newest-first across all jobs, which means the
     * every-minute job buries the hourly ones — an operator scrolling for the
     * roster pull would page through hundreds of punch pushes to find it. This
     * shows each job's own latest result regardless of how noisy its neighbours
     * are, which is the view that actually answers "did the roster download?".
     *
     * @return array<int, array{command:string,label:string,cadence:string,run:?ScheduledTaskRun}>
     */
    private function jobSummary(): array
    {
        return collect(Kernel::CADENCE)
            ->map(fn (string $cadence, string $command) => [
                'command' => $command,
                'label' => ScheduledTaskRun::LABELS[$command] ?? $command,
                'cadence' => $cadence,
                'run' => ScheduledTaskRun::lastRunOf($command),
            ])
            ->values()
            ->all();
    }
}
