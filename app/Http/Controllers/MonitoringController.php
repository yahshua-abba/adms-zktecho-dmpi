<?php

namespace App\Http\Controllers;

use App\Health\SystemHealth;
use App\Queries\DashboardStats;

/**
 * The combined "Monitoring" page — at-a-glance operational stats (from the old
 * Dashboard) plus the system health checks (from the old Health page) on one
 * screen, so operators have a single place to confirm everything is running.
 */
class MonitoringController extends Controller
{
    public function index()
    {
        $checks = SystemHealth::checks();

        return view('monitoring', [
            'stats' => DashboardStats::summary(),
            'checks' => $checks,
            'overall' => SystemHealth::overall($checks),
        ]);
    }
}
