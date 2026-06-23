<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\EmployeeMap;
use App\Queries\EmployeeDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function punch(string $pin, string $ts): void
    {
        Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1',
            'employee_id' => $pin, 'timestamp' => $ts, 'is_sync' => false,
        ]);
    }

    public function test_mapped_lists_roster_with_last_punch(): void
    {
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213, 'name' => 'ABABA, Rubelyn']);
        $this->punch('5_4968', '2026-06-16 08:00:00');
        $this->punch('5_4968', '2026-06-17 08:00:00');

        $mapped = EmployeeDirectory::mapped();

        $this->assertCount(1, $mapped);
        $this->assertSame('ABABA, Rubelyn', $mapped[0]->name);
        $this->assertSame('2026-06-17 08:00:00', (string) $mapped[0]->last_punch_at);
    }

    public function test_mapped_search_matches_every_column(): void
    {
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213, 'name' => 'ABABA, Rubelyn', 'rfid' => '1996052557']);
        EmployeeMap::create(['device_pin' => '7_9343', 'company' => '7', 'chapa' => '9343', 'payroll_employee_id' => 51234, 'name' => 'CRUZ, Juan']);

        $this->assertCount(1, EmployeeDirectory::mapped('rube'));     // name
        $this->assertCount(1, EmployeeDirectory::mapped('9343'));     // chapa / pin
        $this->assertCount(1, EmployeeDirectory::mapped('5_4968'));   // device pin
        $this->assertCount(1, EmployeeDirectory::mapped('1996052557')); // rfid
        $this->assertCount(1, EmployeeDirectory::mapped('48213'));    // payroll id
    }

    public function test_mapped_search_matches_enrolled_device_serial(): void
    {
        EmployeeMap::create(['device_pin' => '270_39475', 'company' => '270', 'chapa' => '39475', 'payroll_employee_id' => 32609, 'name' => 'ABALES, DANICA']);
        \App\Models\Device::create(['no_sn' => 'PYA8261500108', 'nama' => 'X.E', 'payroll_device_code' => 'TEST']);
        \App\Models\DeviceAssignment::create(['device_code' => 'TEST', 'payroll_employee_id' => 32609]);

        $this->assertCount(1, EmployeeDirectory::mapped('PYA8261500108')); // by serial
        $this->assertCount(1, EmployeeDirectory::mapped('X.E'));           // by device name
    }

    public function test_mapped_can_filter_by_physical_device(): void
    {
        EmployeeMap::create(['device_pin' => '270_39475', 'company' => '270', 'chapa' => '39475', 'payroll_employee_id' => 32609, 'name' => 'ON TEST']);
        EmployeeMap::create(['device_pin' => '270_111', 'company' => '270', 'chapa' => '111', 'payroll_employee_id' => 70001, 'name' => 'NOT ON TEST']);
        \App\Models\Device::create(['no_sn' => 'PYA8261500108', 'nama' => 'X.E', 'payroll_device_code' => 'TEST']);
        \App\Models\DeviceAssignment::create(['device_code' => 'TEST', 'payroll_employee_id' => 32609]);

        $filtered = EmployeeDirectory::mapped(null, 'PYA8261500108');

        $this->assertCount(1, $filtered);
        $this->assertSame('ON TEST', $filtered[0]->name);
    }

    public function test_mapped_enrolled_devices_reference_physical_serial(): void
    {
        EmployeeMap::create(['device_pin' => '270_39475', 'company' => '270', 'chapa' => '39475', 'payroll_employee_id' => 32609, 'name' => 'ABALES, DANICA']);
        \App\Models\Device::create(['no_sn' => 'PYA8261500108', 'nama' => 'X.E', 'payroll_device_code' => 'TEST']);
        \App\Models\DeviceAssignment::create(['device_code' => 'TEST', 'payroll_employee_id' => 32609]);

        $devices = EmployeeDirectory::mapped()[0]->devices;

        $this->assertCount(1, $devices);
        $this->assertSame('PYA8261500108', $devices[0]['serial']);
        $this->assertSame('X.E', $devices[0]['name']);
        $this->assertSame('TEST', $devices[0]['code']);
    }

    public function test_mapped_enrolled_device_without_a_linked_reader_falls_back_to_code(): void
    {
        EmployeeMap::create(['device_pin' => '270_39475', 'company' => '270', 'chapa' => '39475', 'payroll_employee_id' => 32609, 'name' => 'ABALES, DANICA']);
        \App\Models\DeviceAssignment::create(['device_code' => 'PBW IN', 'payroll_employee_id' => 32609]);

        $devices = EmployeeDirectory::mapped()[0]->devices;

        $this->assertNull($devices[0]['serial']);
        $this->assertSame('PBW IN', $devices[0]['code']);
    }

    public function test_unmapped_pins_lists_pins_not_in_map_with_counts(): void
    {
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213, 'name' => 'Mapped']);
        $this->punch('5_4968', '2026-06-17 08:00:00'); // mapped, excluded
        $this->punch('5_9999', '2026-06-17 08:00:00'); // unmapped
        $this->punch('5_9999', '2026-06-17 17:00:00'); // unmapped, same pin

        $unmapped = EmployeeDirectory::unmappedPins();

        $this->assertCount(1, $unmapped);
        $this->assertSame('5_9999', (string) $unmapped[0]->employee_id);
        $this->assertSame(2, (int) $unmapped[0]->punch_count);
    }
}
