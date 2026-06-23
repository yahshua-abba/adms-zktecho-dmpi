<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\EmployeeMap;
use App\Queries\AttendanceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceQueryTest extends TestCase
{
    use RefreshDatabase;

    private function punch(array $attrs = []): Attendance
    {
        return Attendance::create(array_merge([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1',
            'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:00:00', 'is_sync' => false,
        ], $attrs));
    }

    public function test_filters_by_date_range(): void
    {
        $this->punch(['timestamp' => '2026-06-01 08:00:00']);
        $mid = $this->punch(['timestamp' => '2026-06-10 08:00:00']);
        $this->punch(['timestamp' => '2026-06-17 08:00:00']);

        $ids = AttendanceQuery::filtered(['date_from' => '2026-06-05', 'date_to' => '2026-06-15'])
            ->pluck('id')->all();

        $this->assertSame([$mid->id], $ids);
    }

    public function test_filters_by_device(): void
    {
        $in = $this->punch(['sn' => 'DEV-IN']);
        $this->punch(['sn' => 'DEV-OUT']);

        $ids = AttendanceQuery::filtered(['device' => 'DEV-IN'])->pluck('id')->all();

        $this->assertSame([$in->id], $ids);
    }

    public function test_filters_by_sync_status(): void
    {
        $synced = $this->punch(['is_sync' => true, 'timestamp' => '2026-06-17 08:00:00']);
        $failed = $this->punch(['is_sync' => false, 'sync_error' => 'No Employee', 'timestamp' => '2026-06-17 08:01:00']);
        $pending = $this->punch(['is_sync' => false, 'sync_error' => null, 'timestamp' => '2026-06-17 08:02:00']);

        $this->assertSame([$synced->id], AttendanceQuery::filtered(['sync' => 'synced'])->pluck('id')->all());
        $this->assertSame([$failed->id], AttendanceQuery::filtered(['sync' => 'failed'])->pluck('id')->all());
        $this->assertSame([$pending->id], AttendanceQuery::filtered(['sync' => 'pending'])->pluck('id')->all());
    }

    public function test_filters_by_employee_chapa_or_name(): void
    {
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213, 'name' => 'ABABA, Rubelyn']);
        $rubelyn = $this->punch(['employee_id' => '5_4968']);
        $this->punch(['employee_id' => '5_9343']);

        $byChapa = AttendanceQuery::filtered(['employee' => '4968'])->pluck('id')->all();
        $byName = AttendanceQuery::filtered(['employee' => 'rube'])->pluck('id')->all();

        $this->assertSame([$rubelyn->id], $byChapa);
        $this->assertSame([$rubelyn->id], $byName);
    }

    public function test_filters_by_company(): void
    {
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213]);
        EmployeeMap::create(['device_pin' => '7_1111', 'company' => '7', 'chapa' => '1111', 'payroll_employee_id' => 70001]);
        $companyFive = $this->punch(['employee_id' => '5_4968']);
        $this->punch(['employee_id' => '7_1111']);

        $ids = AttendanceQuery::filtered(['company' => '5'])->pluck('id')->all();

        $this->assertSame([$companyFive->id], $ids);
    }
}
