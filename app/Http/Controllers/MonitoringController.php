<?php

namespace App\Http\Controllers;

use App\Health\SchedulerGuard;
use App\Health\SystemHealth;
use App\Queries\DashboardStats;

/**
 * The combined "Monitoring" page — at-a-glance operational stats (from the old
 * Dashboard) plus the system health checks (from the old Health page) on one
 * screen, so operators have a single place to confirm everything is running.
 */
class MonitoringController extends Controller
{
    public function index(SchedulerGuard $guard)
    {
        // Restart the scheduler before reading the checks, not after, so the page
        // reflects the repair it just made. The guard is a no-op unless the
        // scheduler is definitely stopped, and throttles itself, so this costs one
        // pgrep on a healthy box. Doing work in a GET is unusual, but this page
        // exists to be refreshed and the alternative — a watchdog on the schedule —
        // is dead in exactly the case it is needed for.
        $restarted = $guard->ensureRunning('noticed on the Monitoring page');

        $checks = SystemHealth::checks();

        return view('monitoring', [
            'stats' => DashboardStats::summary(),
            'checks' => $checks,
            'overall' => SystemHealth::overall($checks),
            'schedulerRestarted' => $restarted,
        ]);
    }
}
