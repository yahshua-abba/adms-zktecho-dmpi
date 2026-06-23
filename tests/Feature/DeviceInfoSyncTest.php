<?php

namespace Tests\Feature;

use App\Models\DeviceAssignment;
use App\Sync\DeviceInfoSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePayrollClient;
use Tests\TestCase;

class DeviceInfoSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_devices_and_assignments(): void
    {
        $fake = new FakePayrollClient();
        $fake->deviceInfo = [
            'devices' => [['code' => 'C1', 'name' => 'Gate 1']],
            'assignments' => [['employee_id' => 48213, 'device_code' => 'C1']],
        ];

        (new DeviceInfoSync($fake))->sync();

        $this->assertDatabaseHas('payroll_devices', ['code' => 'C1', 'name' => 'Gate 1']);
        $this->assertDatabaseHas('device_assignments', ['device_code' => 'C1', 'payroll_employee_id' => 48213]);
    }

    public function test_replaces_assignments_so_removals_propagate(): void
    {
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 999]); // stale

        $fake = new FakePayrollClient();
        $fake->deviceInfo = [
            'devices' => [],
            'assignments' => [['employee_id' => 48213, 'device_code' => 'C1']],
        ];

        (new DeviceInfoSync($fake))->sync();

        $this->assertDatabaseMissing('device_assignments', ['payroll_employee_id' => 999]);
        $this->assertDatabaseHas('device_assignments', ['payroll_employee_id' => 48213]);
    }
}
