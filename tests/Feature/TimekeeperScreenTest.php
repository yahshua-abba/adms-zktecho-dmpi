<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\EmployeeMap;
use App\Models\PayrollDevice;
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
            ->assertSee('Open physical clock Gowning-1');
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
