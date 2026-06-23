<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
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
        \App\Models\EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn', 'rfid' => '55:2D:E3:D3',
        ]);
        \App\Models\DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 48213]);

        $this->post("/devices/{$device->id}/sync-enrollments")->assertRedirect();

        $this->assertSame(1, \App\Models\DeviceCommand::where('device_sn', 'DEV-1')->count());
    }
}
