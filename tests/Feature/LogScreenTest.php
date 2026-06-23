<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_log_page_renders_with_device_filter(): void
    {
        $response = $this->get(route('devices.DeviceLog'));

        $response->assertOk();
        $response->assertSee('Device Check-ins');
        $response->assertSee('Search payload');
        $response->assertSee('All devices');
    }

    public function test_finger_log_page_renders_without_device_filter(): void
    {
        $response = $this->get(route('devices.FingerLog'));

        $response->assertOk();
        $response->assertSee('Device Messages');
        $response->assertDontSee('All devices');
    }

    public function test_device_log_ajax_filters_by_text(): void
    {
        DB::table('device_log')->insert(['data' => 'OPLOG firmware', 'url' => '{}', 'sn' => 'DEV-IN', 'created_at' => now()]);
        DB::table('device_log')->insert(['data' => 'ATTLOG punch', 'url' => '{}', 'sn' => 'DEV-IN', 'created_at' => now()]);

        $response = $this->get(
            route('devices.DeviceLog', ['q' => 'firmware', 'draw' => 1, 'start' => 0, 'length' => 100]),
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertStringContainsString('firmware', $data[0]['data']);
    }
}
