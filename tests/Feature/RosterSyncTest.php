<?php

namespace Tests\Feature;

use App\Models\EmployeeMap;
use App\Sync\RosterSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePayrollClient;
use Tests\TestCase;

class RosterSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_employees_keyed_by_composite_device_pin(): void
    {
        $payroll = new FakePayrollClient();
        $payroll->employees = [
            ['id' => 48213, 'company' => '5', 'chapa' => '4968', 'name' => 'Rubelyn'],
            ['id' => 70001, 'company' => '7', 'chapa' => '4968', 'name' => 'Juan'], // same CHAPA, other company
        ];

        (new RosterSync($payroll))->sync();

        $this->assertSame(2, EmployeeMap::count());
        $this->assertSame(48213, (int) EmployeeMap::where('device_pin', '5_4968')->value('payroll_employee_id'));
        $this->assertSame(70001, (int) EmployeeMap::where('device_pin', '7_4968')->value('payroll_employee_id'));
    }

    public function test_upserts_existing_mapping_by_device_pin_without_duplicating(): void
    {
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 1, 'name' => 'Old']);

        $payroll = new FakePayrollClient();
        $payroll->employees = [['id' => 48213, 'company' => '5', 'chapa' => '4968', 'name' => 'Rubelyn']];

        (new RosterSync($payroll))->sync();

        $this->assertSame(1, EmployeeMap::count());
        $this->assertSame(48213, (int) EmployeeMap::where('device_pin', '5_4968')->value('payroll_employee_id'));
        $this->assertSame('Rubelyn', EmployeeMap::where('device_pin', '5_4968')->value('name'));
    }
}
