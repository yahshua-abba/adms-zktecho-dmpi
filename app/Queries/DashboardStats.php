<?php

namespace App\Queries;

use App\Models\Attendance;
use App\Models\Device;

/**
 * At-a-glance numbers for the Dashboard: device status, payroll-sync health,
 * today's activity, and enrollment gaps (unmapped PINs).
 */
class DashboardStats
{
    public static function summary(): array
    {
        $devices = Device::all();

        return [
            'devices_online' => $devices->filter->isOnline()->count(),
            'devices_offline' => $devices->reject->isOnline()->count(),
            'punches_today' => Attendance::whereDate('timestamp', now()->toDateString())->count(),
            'pending_count' => Attendance::where('is_sync', false)->whereNull('sync_error')->count(),
            'failed_count' => Attendance::where('is_sync', false)->whereNotNull('sync_error')->count(),
            'unmapped_count' => EmployeeDirectory::unmappedPins()->count(),
        ];
    }
}
