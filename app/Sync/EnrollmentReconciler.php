<?php

namespace App\Sync;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\EmployeeMap;

/**
 * Keeps each device's user list in step with its DMPI assignments by queuing
 * push-protocol commands.
 *
 * For a device linked to a payroll device code, the DESIRED users are the
 * assigned employees (resolved through employee_map to PIN/name/RFID-card).
 * It diffs that against what's already been pushed (device_enrollment) and
 * queues "DATA UPDATE USERINFO" for new/changed users and "DATA DELETE
 * USERINFO" for ones no longer assigned — then records the new intended state.
 */
class EnrollmentReconciler
{
    public function reconcileAll(): void
    {
        Device::whereNotNull('payroll_device_code')
            ->pluck('no_sn')
            ->each(fn ($sn) => $this->reconcileDevice($sn));
    }

    public function reconcileDevice(string $deviceSn): void
    {
        $device = Device::where('no_sn', $deviceSn)->first();
        if ($device === null || $device->payroll_device_code === null) {
            return; // not linked to a payroll device — nothing to enroll
        }

        $desired = $this->desiredUsers($device->payroll_device_code);
        $current = DeviceEnrollment::where('device_sn', $deviceSn)->get()->keyBy('pin');

        // Add or update.
        foreach ($desired as $pin => $user) {
            $existing = $current->get($pin);
            if ($existing === null || $existing->name !== $user['name'] || $existing->card !== $user['card']) {
                $this->queue($deviceSn, $this->updateCommand($user));
                DeviceEnrollment::updateOrCreate(
                    ['device_sn' => $deviceSn, 'pin' => $pin],
                    ['name' => $user['name'], 'card' => $user['card']],
                );
            }
        }

        // Remove anyone no longer assigned.
        foreach ($current as $pin => $enrollment) {
            if (! $desired->has($pin)) {
                $this->queue($deviceSn, "DATA DELETE USERINFO PIN={$pin}");
                $enrollment->delete();
            }
        }
    }

    /** @return \Illuminate\Support\Collection<string, array{pin:string,name:?string,card:?string}> */
    private function desiredUsers(string $payrollDeviceCode)
    {
        $assignedIds = DeviceAssignment::where('device_code', $payrollDeviceCode)->pluck('payroll_employee_id');

        return EmployeeMap::whereIn('payroll_employee_id', $assignedIds)
            ->get()
            ->keyBy('device_pin')
            ->map(fn ($e) => [
                'pin' => $e->device_pin,
                'name' => $e->name,
                'card' => RfidConverter::toCard($e->rfid),
            ]);
    }

    private function updateCommand(array $user): string
    {
        return "DATA UPDATE USERINFO PIN={$user['pin']}\tName={$user['name']}\tPrivilege=0\tCard={$user['card']}";
    }

    private function queue(string $deviceSn, string $body): void
    {
        DeviceCommand::create(['device_sn' => $deviceSn, 'body' => $body, 'status' => 'pending']);
    }
}
