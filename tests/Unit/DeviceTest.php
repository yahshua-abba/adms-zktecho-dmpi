<?php

namespace Tests\Unit;

use App\Models\Device;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    public function test_device_is_online_when_seen_within_threshold(): void
    {
        $device = new Device(['online' => now()->subMinutes(2)]);

        $this->assertTrue($device->isOnline());
        $this->assertSame('online', $device->status);
    }

    public function test_device_is_offline_when_last_contact_exceeds_threshold(): void
    {
        $device = new Device(['online' => now()->subMinutes(10)]);

        $this->assertFalse($device->isOnline());
        $this->assertSame('offline', $device->status);
    }

    public function test_device_never_seen_is_offline(): void
    {
        $device = new Device();

        $this->assertFalse($device->isOnline());
    }
}
