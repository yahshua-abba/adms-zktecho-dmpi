<?php

namespace Tests\Feature;

use App\Contracts\PayrollClient;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\EmployeeMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePayrollClient;
use Tests\TestCase;

class SyncCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_attendances_command_pushes_and_marks_synced(): void
    {
        $fake = new FakePayrollClient();
        $this->app->instance(PayrollClient::class, $fake);

        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213]);
        $attendance = Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '9999',
            'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:01:33', 'log_type' => 'in', 'is_sync' => false,
        ]);

        $this->artisan('payroll:sync-attendances')->assertSuccessful();

        $this->assertCount(1, $fake->pushed);
        $attendance->refresh();
        $this->assertTrue($attendance->is_sync);
    }

    public function test_sync_roster_command_imports_employees(): void
    {
        $fake = new FakePayrollClient();
        $fake->employees = [['id' => 48213, 'company' => '5', 'chapa' => '4968', 'name' => 'Rubelyn']];
        $this->app->instance(PayrollClient::class, $fake);

        $this->artisan('payroll:sync-roster')->assertSuccessful();

        $this->assertSame(1, EmployeeMap::count());
        $this->assertSame(48213, (int) EmployeeMap::where('device_pin', '5_4968')->value('payroll_employee_id'));
    }

    public function test_sync_devices_command_imports_assignments(): void
    {
        $fake = new FakePayrollClient();
        $fake->deviceInfo = [
            'devices' => [['code' => 'C1', 'name' => 'Gate 1']],
            'assignments' => [['employee_id' => 48213, 'device_code' => 'C1']],
        ];
        $this->app->instance(PayrollClient::class, $fake);

        $this->artisan('payroll:sync-devices')->assertSuccessful();

        $this->assertDatabaseHas('device_assignments', ['device_code' => 'C1', 'payroll_employee_id' => 48213]);
    }

    public function test_reconcile_enrollments_command_queues_commands(): void
    {
        Device::create(['no_sn' => 'DEV-1', 'direction' => 'in', 'payroll_device_code' => 'C1']);
        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn', 'rfid' => '55:2D:E3:D3',
        ]);
        \App\Models\DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 48213]);

        $this->artisan('payroll:reconcile-enrollments')->assertSuccessful();

        $this->assertSame(1, \App\Models\DeviceCommand::where('device_sn', 'DEV-1')->count());
    }
}
