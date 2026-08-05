<?php

namespace App\Http\Controllers;

use App\Health\SchedulerControl;
use App\Health\SchedulerGuard;
use App\Health\SystemHealth;
use App\Models\ActivityLog;

class HealthController extends Controller
{
    // "Start scheduler" button on the Health page — recovers the scheduler
    // worker without the operator needing terminal access.
    public function startScheduler(SchedulerControl $scheduler)
    {
        if ($scheduler->isRunning()) {
            return redirect()->route('monitoring')->with('success', 'Scheduler is already running.');
        }

        $scheduler->start();
        ActivityLog::record('scheduler.start', 'Scheduler (re)started from the Health page.');

        return redirect()->route('monitoring')->with('success', 'Scheduler started — the first sync runs within a minute.');
    }

    public function index()
    {
        $checks = SystemHealth::checks();

        return view('health.index', [
            'checks' => $checks,
            'overall' => SystemHealth::overall($checks),
        ]);
    }

    /** Machine-readable health for external uptime monitors. 200 if ok/warn, 503 if fail. */
    public function json(SchedulerGuard $guard)
    {
        // The most reliable heartbeat this box has: an uptime monitor polls this
        // every minute or two, around the clock, long after anyone has stopped
        // looking at the dashboard. Piggy-backing the watchdog on it means a
        // scheduler that dies at 2am is back within one poll.
        $guard->ensureRunning('noticed by a /healthz check');

        $checks = SystemHealth::checks();
        $overall = SystemHealth::overall($checks);

        return response()->json([
            'status' => $overall,
            'checks' => $checks,
        ], $overall === 'fail' ? 503 : 200);
    }
}
