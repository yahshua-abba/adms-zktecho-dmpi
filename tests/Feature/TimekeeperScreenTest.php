<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\EmployeeMap;
use App\Models\PayrollDevice;
use App\Models\PinCollision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimekeeperScreenTest extends TestCase
{
    use RefreshDatabase;

    private function seedEstate(): void
    {
        PayrollDevice::create(['code' => 'GOWNING 1', 'name' => 'GOWNING_AREA']);
        PayrollDevice::create(['code' => 'TEST2', 'name' => 'TEST']);
        Device::create(['no_sn' => 'DEV1', 'nama' => 'Gowning-1', 'payroll_device_code' => 'GOWNING 1']);
        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn', 'rfid' => '55:2D:E3:D3',
        ]);
        DeviceAssignment::create(['device_code' => 'GOWNING 1', 'payroll_employee_id' => 48213]);
    }

    public function test_index_requires_login(): void
    {
        $this->guest()->get(route('devices.timekeepers'))->assertRedirect(route('login'));
    }

    public function test_index_lists_payroll_devices(): void
    {
        $this->seedEstate();

        $this->get(route('devices.timekeepers'))
            ->assertOk()
            ->assertSee('GOWNING 1')
            ->assertSee('TEST2');
    }

    public function test_detail_shows_the_assigned_people(): void
    {
        $this->seedEstate();

        $this->get(route('devices.timekeepers.show', ['code' => 'GOWNING 1']))
            ->assertOk()
            ->assertSee('Rubelyn')
            ->assertSee('5_4968')
            ->assertSee('Eligible for enrollment')
            ->assertSee('Open physical clock Gowning-1')
            ->assertSee('Sync this person');
    }

    public function test_an_eligible_person_can_be_synced_to_one_linked_clock(): void
    {
        $this->seedEstate();
        $device = Device::where('no_sn', 'DEV1')->firstOrFail();

        $response = $this->post(route('devices.timekeepers.people.sync', [
            'code' => 'GOWNING 1',
            'payrollEmployeeId' => 48213,
            'device' => $device,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('device_commands', [
            'device_sn' => 'DEV1',
            'status' => 'pending',
        ]);
        $this->assertStringContainsString('PIN=5_4968', DeviceCommand::firstOrFail()->body);
    }

    public function test_clocks_with_the_same_name_are_distinguished_by_serial(): void
    {
        $this->seedEstate();
        Device::create(['no_sn' => 'DEV3', 'nama' => 'Gowning-1', 'payroll_device_code' => 'GOWNING 1']);

        $this->get(route('devices.timekeepers.show', ['code' => 'GOWNING 1']))
            ->assertOk()
            ->assertSee('Gowning-1 · DEV1')
            ->assertSee('Gowning-1 · DEV3');
    }

    public function test_individual_sync_rejects_a_clock_linked_to_another_payroll_device(): void
    {
        $this->seedEstate();
        $other = Device::create(['no_sn' => 'OTHER', 'payroll_device_code' => 'TEST2']);

        $response = $this->post(route('devices.timekeepers.people.sync', [
            'code' => 'GOWNING 1',
            'payrollEmployeeId' => 48213,
            'device' => $other,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, DeviceCommand::count());
    }

    public function test_blocked_people_get_a_guide_for_their_specific_problem(): void
    {
        $this->seedEstate();
        DeviceAssignment::create(['device_code' => 'GOWNING 1', 'payroll_employee_id' => 99999]);
        DeviceAssignment::create(['device_code' => 'GOWNING 1', 'payroll_employee_id' => 271]);
        PinCollision::create([
            'device_pin' => '271_14257',
            'claimants' => [
                ['payroll_employee_id' => 271, 'name' => 'One'],
                ['payroll_employee_id' => 272, 'name' => 'Two'],
            ],
        ]);

        $this->get(route('devices.timekeepers.show', ['code' => 'GOWNING 1']))
            ->assertOk()
            ->assertSee('How to make this eligible')
            ->assertSee('Download employees')
            ->assertSee('Resolve PIN conflict');
    }

    public function test_an_eligible_person_without_a_reader_is_told_to_link_one_first(): void
    {
        $this->seedEstate();
        DeviceAssignment::create(['device_code' => 'TEST2', 'payroll_employee_id' => 48213]);

        $this->get(route('devices.timekeepers.show', ['code' => 'TEST2']))
            ->assertOk()
            ->assertSee('Link a physical clock first');
    }

    public function test_individual_sync_requires_login(): void
    {
        $this->seedEstate();
        $device = Device::where('no_sn', 'DEV1')->firstOrFail();

        $this->guest()->post(route('devices.timekeepers.people.sync', [
            'code' => 'GOWNING 1',
            'payrollEmployeeId' => 48213,
            'device' => $device,
        ]))->assertRedirect(route('login'));
    }

    /**
     * A code with a space survives the route: DMPI's codes are human-written
     * ("GOWNING 1"), and a constraint that rejected them would 404 the very
     * devices this screen is for.
     */
    public function test_detail_handles_a_code_with_a_space(): void
    {
        $this->seedEstate();

        $this->get(route('devices.timekeepers.show', ['code' => 'GOWNING 1']))->assertOk();
    }

    /**
     * "No such door" and "this door is empty" want different reactions, so the
     * unknown code must not render as an empty table.
     */
    public function test_unknown_code_is_a_404(): void
    {
        $this->seedEstate();

        $this->get(route('devices.timekeepers.show', ['code' => 'NOPE']))->assertNotFound();
    }

    public function test_empty_device_warns_about_linking_a_clock_to_it(): void
    {
        $this->seedEstate();

        $this->get(route('devices.timekeepers.show', ['code' => 'TEST2']))
            ->assertOk()
            ->assertSee('Payroll assigns nobody to this device');
    }

    /**
     * The timekeepers path is registered before devices/{device}/*, so the word
     * must not be swallowed as a device key.
     */
    public function test_timekeepers_path_is_not_taken_for_a_device(): void
    {
        $this->seedEstate();

        $this->get('/devices/timekeepers')->assertOk();
    }
}
