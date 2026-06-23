<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\EmployeeMap;
use App\Sync\EnrollmentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private function setupDevice(): void
    {
        Device::create(['no_sn' => 'DEV1', 'direction' => 'in', 'payroll_device_code' => 'C1']);
        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn', 'rfid' => '55:2D:E3:D3',
        ]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 48213]);
    }

    public function test_queues_update_command_for_newly_assigned_employee(): void
    {
        $this->setupDevice();

        (new EnrollmentReconciler())->reconcileDevice('DEV1');

        $cmd = DeviceCommand::where('device_sn', 'DEV1')->first();
        $this->assertNotNull($cmd);
        $this->assertSame('pending', $cmd->status);
        $this->assertStringContainsString('DATA UPDATE USERINFO PIN=5_4968', $cmd->body);
        $this->assertStringContainsString("Name=Rubelyn", $cmd->body);
        $this->assertStringContainsString('Card=55:2D:E3:D3', $cmd->body); // RFID pushed as-is (no conversion)

        $this->assertDatabaseHas('device_enrollment', ['device_sn' => 'DEV1', 'pin' => '5_4968', 'card' => '55:2D:E3:D3']);
    }

    public function test_is_idempotent_no_duplicate_when_nothing_changed(): void
    {
        $this->setupDevice();
        $reconciler = new EnrollmentReconciler();

        $reconciler->reconcileDevice('DEV1');
        $reconciler->reconcileDevice('DEV1'); // second run

        $this->assertSame(1, DeviceCommand::where('device_sn', 'DEV1')->count());
    }

    public function test_queues_update_when_card_changes(): void
    {
        $this->setupDevice();
        (new EnrollmentReconciler())->reconcileDevice('DEV1');
        DeviceCommand::query()->delete(); // ignore the first add

        // RFID changes in payroll (e.g. re-issued card)
        EmployeeMap::where('device_pin', '5_4968')->update(['rfid' => '40:33:A7:BD']);
        (new EnrollmentReconciler())->reconcileDevice('DEV1');

        $cmd = DeviceCommand::where('device_sn', 'DEV1')->first();
        $this->assertNotNull($cmd);
        $this->assertStringContainsString('DATA UPDATE USERINFO PIN=5_4968', $cmd->body);
    }

    public function test_queues_delete_when_employee_unassigned(): void
    {
        $this->setupDevice();
        (new EnrollmentReconciler())->reconcileDevice('DEV1'); // enrolls them
        DeviceCommand::query()->delete();

        DeviceAssignment::query()->delete(); // unassigned in payroll
        (new EnrollmentReconciler())->reconcileDevice('DEV1');

        $cmd = DeviceCommand::where('device_sn', 'DEV1')->first();
        $this->assertNotNull($cmd);
        $this->assertSame('DATA DELETE USERINFO PIN=5_4968', $cmd->body);
        $this->assertDatabaseMissing('device_enrollment', ['device_sn' => 'DEV1', 'pin' => '5_4968']);
    }

    public function test_skips_device_not_linked_to_payroll(): void
    {
        Device::create(['no_sn' => 'DEV2', 'direction' => 'in', 'payroll_device_code' => null]);

        (new EnrollmentReconciler())->reconcileDevice('DEV2');

        $this->assertSame(0, DeviceCommand::count());
    }
}
