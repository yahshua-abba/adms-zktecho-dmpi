<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\EmployeeMap;
use App\Queries\DashboardStats;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_reports_device_status_sync_health_and_gaps(): void
    {
        Carbon::setTestNow('2026-06-17 10:00:00');

        Device::create(['no_sn' => 'A', 'online' => now()->subMinutes(1)]);   // online
        Device::create(['no_sn' => 'B', 'online' => now()->subMinutes(30)]);  // offline

        $punch = fn (array $a) => Attendance::create(array_merge([
            'sn' => 'A', 'table' => 'ATTLOG', 'stamp' => '1',
            'employee_id' => '5_4968', 'timestamp' => now(), 'is_sync' => false,
        ], $a));

        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 1, 'name' => 'Mapped']);

        $punch(['timestamp' => now(), 'is_sync' => true]);                            // today, synced
        $punch(['timestamp' => now()->subDay(), 'is_sync' => true]);                  // yesterday
        $punch(['timestamp' => now()->subMinutes(1), 'is_sync' => false, 'sync_error' => null]); // pending
        $punch(['timestamp' => now()->subMinutes(2), 'is_sync' => false, 'sync_error' => 'x']);  // failed
        $punch(['timestamp' => now(), 'employee_id' => '5_9999']);                    // unmapped PIN

        $summary = DashboardStats::summary();

        $this->assertSame(1, $summary['devices_online']);
        $this->assertSame(1, $summary['devices_offline']);
        $this->assertSame(4, $summary['punches_today']);   // all but the yesterday one
        $this->assertSame(2, $summary['pending_count']);   // pending + the unmapped (both is_sync=false, no error)
        $this->assertSame(1, $summary['failed_count']);
        $this->assertSame(1, $summary['unmapped_count']);  // PIN 9999
    }
}
