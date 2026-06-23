<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\DeviceAssignment;
use App\Models\PayrollDevice;
use App\Sync\DeviceInfoSync;
use Illuminate\Console\Command;

class SyncDevicesCommand extends Command
{
    protected $signature = 'payroll:sync-devices';

    protected $description = 'Pull the device list and device-employee assignments from DMPI';

    public function handle(DeviceInfoSync $sync): int
    {
        // DMPI's read_device_info can be large (cluster-wide); give the parse headroom.
        ini_set('memory_limit', '2048M');

        try {
            $sync->sync();
            ActivityLog::record('devices.sync', 'Device info pull complete. Devices: '.PayrollDevice::count().', assignments: '.DeviceAssignment::count().'.');
            $this->info('Device info synced.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            ActivityLog::record('devices.sync', 'Device info pull failed: '.$e->getMessage(), 'error');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
