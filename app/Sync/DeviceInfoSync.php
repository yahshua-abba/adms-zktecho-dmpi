<?php

namespace App\Sync;

use App\Contracts\PayrollClient;
use App\Models\DeviceAssignment;
use App\Models\PayrollDevice;

/**
 * Mirrors DMPI's device list and device-employee assignments into local tables
 * so the enrollment reconciler reads only edge-side data. Assignments are
 * fully replaced each run so removals in DMPI propagate (the reconciler then
 * deletes those users from the device).
 */
class DeviceInfoSync
{
    public function __construct(private PayrollClient $payroll)
    {
    }

    public function sync(): void
    {
        $info = $this->payroll->fetchDeviceInfo();

        foreach ($info['devices'] as $device) {
            PayrollDevice::updateOrCreate(['code' => $device['code']], ['name' => $device['name'] ?? null]);
        }

        DeviceAssignment::query()->delete();
        foreach ($info['assignments'] as $assignment) {
            DeviceAssignment::updateOrCreate([
                'device_code' => $assignment['device_code'],
                'payroll_employee_id' => $assignment['employee_id'],
            ], []);
        }
    }
}
