<?php

namespace App\Http\Controllers;

use App\Health\SchedulerControl;
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
    public function json()
    {
        $checks = SystemHealth::checks();
        $overall = SystemHealth::overall($checks);

        return response()->json([
            'status' => $overall,
            'checks' => $checks,
        ], $overall === 'fail' ? 503 : 200);
    }
}
