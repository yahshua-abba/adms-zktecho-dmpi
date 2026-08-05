<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\EmployeeMap;
use App\Models\PayrollDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_set_device_direction_name_and_location(): void
    {
        $device = Device::create(['no_sn' => 'DEV-1']);

        $this->patch("/devices/{$device->id}", [
            'direction' => 'in',
            'nama' => 'Main Entrance',
            'lokasi' => 'Gate 1',
        ])->assertRedirect();

        $device->refresh();
        $this->assertSame('in', $device->direction);
        $this->assertSame('Main Entrance', $device->nama);
        $this->assertSame('Gate 1', $device->lokasi);
    }

    public function test_rejects_invalid_direction(): void
    {
        $device = Device::create(['no_sn' => 'DEV-1', 'direction' => 'in']);

        $this->patch("/devices/{$device->id}", ['direction' => 'sideways'])
            ->assertSessionHasErrors('direction');

        $device->refresh();
        $this->assertSame('in', $device->direction);
    }

    public function test_per_device_log_link_redirects_to_attendance_filtered_by_device(): void
    {
        $device = Device::create(['no_sn' => 'DEV-1', 'direction' => 'in']);

        $this->get("/devices/{$device->id}/logs")
            ->assertRedirect(route('devices.Attendance', ['device' => 'DEV-1']));
    }

    public function test_devices_page_shows_online_and_offline_badges(): void
    {
        Device::create(['no_sn' => 'DEV-ON', 'online' => now()->subMinutes(1)]);
        Device::create(['no_sn' => 'DEV-OFF', 'online' => now()->subMinutes(30)]);

        $response = $this->get('/devices');

        $response->assertOk();
        $response->assertSee('Online');
        $response->assertSee('Offline');
    }

    public function test_status_endpoint_returns_live_online_state(): void
    {
        Device::create(['no_sn' => 'DEV-ON', 'online' => now()->subMinutes(1)]);
        Device::create(['no_sn' => 'DEV-OFF', 'online' => now()->subMinutes(30)]);

        $response = $this->getJson('/devices-status');

        $response->assertOk();
        $response->assertJsonPath('DEV-ON.online', true);
        $response->assertJsonPath('DEV-OFF.online', false);
    }

    public function test_can_link_device_to_a_payroll_device(): void
    {
        $device = Device::create(['no_sn' => 'DEV-1', 'direction' => 'in']);

        $this->patch("/devices/{$device->id}", ['payroll_device_code' => 'C1'])->assertRedirect();

        $this->assertSame('C1', $device->fresh()->payroll_device_code);
    }

    public function test_sync_enrollments_button_queues_commands(): void
    {
        $device = Device::create(['no_sn' => 'DEV-1', 'direction' => 'in', 'payroll_device_code' => 'C1']);
        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn', 'rfid' => '55:2D:E3:D3',
        ]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 48213]);

        $this->post("/devices/{$device->id}/sync-enrollments")->assertRedirect();

        $this->assertSame(1, DeviceCommand::where('device_sn', 'DEV-1')->count());
    }

    /**
     * DMPI's device list used to render ONLY as a dropdown inside each physical
     * device row. On a server where no clock had checked in, a freshly downloaded
     * set of 89 devices therefore appeared nowhere on the page, and a download that
     * had actually worked looked like it had failed.
     */
    public function test_payroll_devices_are_visible_even_with_no_clocks_checked_in(): void
    {
        PayrollDevice::create(['code' => 'Admin IN', 'name' => 'Admin']);
        PayrollDevice::create(['code' => 'SP - Asiapro - Tube - IN', 'name' => 'Admin']);

        $this->assertSame(0, Device::count(), 'this test is about the no-clocks case');

        $this->get(route('devices.index'))
            ->assertOk()
            ->assertSee('Payroll devices from DMPI')
            ->assertSee('Admin IN')
            ->assertSee('SP - Asiapro - Tube - IN')
            ->assertSee('No time clock has contacted this server yet');
    }

    public function test_the_panel_says_which_payroll_devices_have_a_clock_attached(): void
    {
        PayrollDevice::create(['code' => 'Admin IN', 'name' => 'Admin']);
        PayrollDevice::create(['code' => 'Gate 2', 'name' => 'Gate']);
        Device::create(['no_sn' => 'CLOCK-1', 'direction' => 'in', 'payroll_device_code' => 'Admin IN']);

        $this->get(route('devices.index'))
            ->assertOk()
            ->assertSee('1 linked to a clock here')
            ->assertSee('1 not yet')
            ->assertSee('CLOCK-1');
    }

    public function test_the_empty_state_explains_itself_rather_than_saying_no_data(): void
    {
        $this->get(route('devices.index'))
            ->assertOk()
            ->assertSee('No time clocks have checked in to this server yet.')
            ->assertSee('Nothing downloaded yet');
    }

    public function test_removing_a_device_keeps_its_punches(): void
    {
        $device = Device::create(['no_sn' => 'OLD-CLOCK', 'direction' => 'in']);
        Attendance::create([
            'sn' => 'OLD-CLOCK', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_1',
            'timestamp' => '2026-06-17 08:00:00', 'log_type' => 'in', 'is_sync' => false,
        ]);

        $this->delete(route('devices.destroy', $device->id))
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('success');

        $this->assertSame(0, Device::where('no_sn', 'OLD-CLOCK')->count());
        // Punches are the data of record — losing them with the hardware would be
        // losing somebody's hours.
        $this->assertSame(1, Attendance::where('sn', 'OLD-CLOCK')->count());
    }

    /**
     * The enrolled-user list must go with the device. If it survived, a clock that
     * came back (or was factory reset) would be compared against a stale list, the
     * reconciler would decide its users were already loaded, and it would be left
     * with nobody able to badge in.
     */
    public function test_removing_a_device_clears_what_we_believed_was_on_it(): void
    {
        $device = Device::create(['no_sn' => 'OLD-CLOCK', 'direction' => 'in', 'payroll_device_code' => 'C1']);
        DeviceEnrollment::create(['device_sn' => 'OLD-CLOCK', 'pin' => '5_1', 'name' => 'A', 'card' => null]);
        DeviceCommand::create(['device_sn' => 'OLD-CLOCK', 'body' => 'DATA UPDATE USERINFO PIN=5_1', 'status' => 'pending']);

        $this->delete(route('devices.destroy', $device->id))->assertRedirect();

        $this->assertSame(0, DeviceEnrollment::where('device_sn', 'OLD-CLOCK')->count());
        $this->assertSame(0, DeviceCommand::where('device_sn', 'OLD-CLOCK')->count());
    }

    public function test_a_removed_device_comes_back_if_it_checks_in_again(): void
    {
        $device = Device::create(['no_sn' => 'BACK-AGAIN']);
        $this->delete(route('devices.destroy', $device->id))->assertRedirect();
        $this->assertSame(0, Device::count());

        // Devices add themselves; removing one does not ban it.
        $this->get('/iclock/cdata?SN=BACK-AGAIN&options=all')->assertOk();

        $this->assertSame(1, Device::where('no_sn', 'BACK-AGAIN')->count());
    }

    public function test_removal_is_recorded_in_server_activity(): void
    {
        $device = Device::create(['no_sn' => 'AUDIT-ME']);

        $this->delete(route('devices.destroy', $device->id));

        $this->assertDatabaseHas('activity_log', ['event' => 'device.removed', 'level' => 'warning']);
    }

    public function test_the_devices_page_offers_removal(): void
    {
        $device = Device::create(['no_sn' => 'SHOW-ME']);

        $this->get(route('devices.index'))
            ->assertOk()
            ->assertSee('Remove')
            ->assertSee(route('devices.destroy', $device->id));
    }

    /**
     * Device serials arrive over /iclock/*, which has no login by design so that
     * clocks can post without one — meaning anything on the device LAN chooses its
     * own serial. Building the removal confirm as an inline onsubmit put that
     * straight into a JS string literal, and because the HTML parser decodes
     * entities before the JS engine runs, an apostrophe closed the string and the
     * rest of the serial executed in the operator's browser.
     */
    public function test_a_device_chosen_serial_cannot_run_script_in_the_dashboard(): void
    {
        $hostile = "EVIL'); alert(1); //";

        // Registered exactly the way a real device would.
        $this->get('/iclock/cdata?'.http_build_query(['SN' => $hostile, 'options' => 'all']))->assertOk();
        $this->assertSame(1, Device::where('no_sn', $hostile)->count());

        $html = $this->get(route('devices.index'))->assertOk()->getContent();

        // Static inline confirms (the download buttons) are fine — they carry no
        // user data. What must never happen is device-supplied text reaching one.
        preg_match_all('/on[a-z]+="([^"]*)"/i', $html, $handlers);
        foreach ($handlers[1] as $handler) {
            $this->assertStringNotContainsString(
                'EVIL', $handler,
                'a device-chosen serial reached an inline event handler, where it can break out of the JS string'
            );
        }

        $this->assertStringNotContainsString('alert(1); // from this server', $html);

        // It is still shown to the operator — just inert, as escaped HTML text.
        $this->assertStringContainsString('EVIL&#039;); alert(1); //', $html);
    }
}
