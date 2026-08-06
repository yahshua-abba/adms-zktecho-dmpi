<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\EmployeeMap;
use App\Models\PayrollDevice;
use App\Models\PinCollision;
use App\Queries\TimekeeperDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimekeeperDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function seedEstate(): void
    {
        PayrollDevice::create(['code' => 'GOWNING 1', 'name' => 'GOWNING_AREA']);
        PayrollDevice::create(['code' => 'TEST2', 'name' => 'TEST']);
        PayrollDevice::create(['code' => 'SPARE', 'name' => 'Spare door']);

        // A reader on this box pointed at GOWNING 1.
        Device::create(['no_sn' => 'DEV1', 'nama' => 'Gowning-1', 'payroll_device_code' => 'GOWNING 1']);
        // A reader pointed at nothing.
        Device::create(['no_sn' => 'DEV2', 'nama' => 'Tube-1']);

        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn', 'rfid' => '55:2D:E3:D3',
        ]);

        // Assigned to GOWNING 1 and mappable.
        DeviceAssignment::create(['device_code' => 'GOWNING 1', 'payroll_employee_id' => 48213]);
        // Assigned, but nothing in employee_map — never came down in the roster.
        DeviceAssignment::create(['device_code' => 'GOWNING 1', 'payroll_employee_id' => 99999]);
        // Assigned, and unmapped because two employees claim the PIN.
        DeviceAssignment::create(['device_code' => 'GOWNING 1', 'payroll_employee_id' => 271]);
        PinCollision::create([
            'device_pin' => '271_14257',
            'claimants' => [
                ['payroll_employee_id' => 271, 'name' => 'One'],
                ['payroll_employee_id' => 272, 'name' => 'Two'],
            ],
        ]);
    }

    public function test_lists_payroll_devices_with_assigned_and_blocked_counts(): void
    {
        $this->seedEstate();

        $devices = TimekeeperDirectory::devices()->keyBy('code');

        $this->assertSame(3, $devices->get('GOWNING 1')->assigned);
        $this->assertSame(2, $devices->get('GOWNING 1')->blocked);
        $this->assertSame(1, $devices->get('GOWNING 1')->enrollable);
        $this->assertSame(['DEV1'], $devices->get('GOWNING 1')->readers->pluck('no_sn')->all());

        // The trap this screen exists to expose.
        $this->assertSame(0, $devices->get('TEST2')->assigned);
        $this->assertTrue($devices->get('TEST2')->readers->isEmpty());
    }

    public function test_filters_by_linked_and_by_emptiness(): void
    {
        $this->seedEstate();

        $linked = TimekeeperDirectory::devices(['filter' => 'linked'])->pluck('code')->all();
        $this->assertSame(['GOWNING 1'], $linked);

        $unlinked = TimekeeperDirectory::devices(['filter' => 'unlinked'])->pluck('code')->all();
        $this->assertSame(['SPARE', 'TEST2'], $unlinked);

        $empty = TimekeeperDirectory::devices(['filter' => 'empty'])->pluck('code')->all();
        $this->assertSame(['SPARE', 'TEST2'], $empty);

        $populated = TimekeeperDirectory::devices(['filter' => 'populated'])->pluck('code')->all();
        $this->assertSame(['GOWNING 1'], $populated);
    }

    public function test_filter_counts_the_whole_set_not_just_the_page(): void
    {
        $this->seedEstate();

        // Page size of 1 with two matches: the total must still say two, or the
        // footer would contradict the filter that produced it.
        $unlinked = TimekeeperDirectory::devices(['filter' => 'unlinked'], 1);

        $this->assertSame(2, $unlinked->total());
        $this->assertCount(1, $unlinked->items());
    }

    public function test_search_matches_code_or_location(): void
    {
        $this->seedEstate();

        $this->assertSame(['GOWNING 1'], TimekeeperDirectory::devices(['search' => 'gowning'])->pluck('code')->all());
        $this->assertSame(['SPARE'], TimekeeperDirectory::devices(['search' => 'Spare door'])->pluck('code')->all());
    }

    public function test_people_separates_enrollable_from_blocked_and_names_the_cause(): void
    {
        $this->seedEstate();

        $result = TimekeeperDirectory::people('GOWNING 1');

        $this->assertSame(3, $result['summary']['assigned']);
        $this->assertSame(1, $result['summary']['enrollable']);
        $this->assertSame(2, $result['summary']['blocked']);

        $byId = collect($result['people']->items())->keyBy('payroll_employee_id');

        $mapped = $byId->get(48213);
        $this->assertSame(TimekeeperDirectory::ENROLLABLE, $mapped['status']);
        $this->assertSame('Rubelyn', $mapped['name']);
        $this->assertSame('5_4968', $mapped['pin']);
        $this->assertSame('[552DE3D3]', $mapped['card']);

        // A contested PIN and a missing roster row are both "can't be added", but
        // they have different fixes, so they must not share a reason.
        $contested = $byId->get(271);
        $this->assertSame(TimekeeperDirectory::BLOCKED, $contested['status']);
        $this->assertSame('271_14257', $contested['pin']);
        $this->assertStringContainsString('PIN conflicts', $contested['reason']);

        $missing = $byId->get(99999);
        $this->assertSame(TimekeeperDirectory::BLOCKED, $missing['status']);
        $this->assertNull($missing['pin']);
        $this->assertStringContainsString('No employee record', $missing['reason']);
    }

    public function test_blocked_people_sort_first(): void
    {
        $this->seedEstate();

        $statuses = collect(TimekeeperDirectory::people('GOWNING 1')['people']->items())->pluck('status')->all();

        $this->assertSame([
            TimekeeperDirectory::BLOCKED,
            TimekeeperDirectory::BLOCKED,
            TimekeeperDirectory::ENROLLABLE,
        ], $statuses);
    }

    public function test_people_filters_do_not_change_the_summary(): void
    {
        $this->seedEstate();

        $result = TimekeeperDirectory::people('GOWNING 1', ['status' => TimekeeperDirectory::ENROLLABLE]);

        $this->assertCount(1, $result['people']->items());
        // The tiles describe the device, not the filtered page.
        $this->assertSame(3, $result['summary']['assigned']);
        $this->assertSame(2, $result['summary']['blocked']);
    }

    public function test_people_search_reaches_pin_and_payroll_id(): void
    {
        $this->seedEstate();

        $this->assertCount(1, TimekeeperDirectory::people('GOWNING 1', ['search' => '5_4968'])['people']->items());
        $this->assertCount(1, TimekeeperDirectory::people('GOWNING 1', ['search' => '99999'])['people']->items());
        $this->assertCount(0, TimekeeperDirectory::people('GOWNING 1', ['search' => 'nobody'])['people']->items());
    }

    public function test_empty_device_returns_no_people_without_touching_the_roster(): void
    {
        $this->seedEstate();

        $result = TimekeeperDirectory::people('TEST2');

        $this->assertSame(0, $result['summary']['assigned']);
        $this->assertCount(0, $result['people']->items());
    }

    public function test_summary_counts_the_whole_estate(): void
    {
        $this->seedEstate();

        $this->assertSame([
            'devices' => 3,
            'linked' => 1,
            'assignments' => 3,
            'empty' => 2,
        ], TimekeeperDirectory::summary());
    }
}
