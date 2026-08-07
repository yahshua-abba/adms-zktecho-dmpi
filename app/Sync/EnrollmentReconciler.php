<?php

namespace App\Sync;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\EmployeeMap;
use Illuminate\Support\Collection;

/**
 * Keeps each device's user list in step with its DMPI assignments by queuing
 * push-protocol commands.
 *
 * For a device linked to a payroll device code, the DESIRED users are the
 * assigned employees (resolved through employee_map to PIN/name/RFID-card).
 * It diffs that against what's already been pushed (device_enrollment) and
 * queues "DATA UPDATE USERINFO" for new/changed users and "DATA DELETE
 * USERINFO" for ones no longer assigned — then records the new intended state.
 *
 * A contested device PIN has no employee_map row on purpose (see RosterSync),
 * so it simply never appears in the desired set: an ambiguous PIN can't be
 * enrolled under a guessed name any more than its punches can be pushed to a
 * guessed employee.
 *
 * The diff is computed first and written in chunks — a device with thousands of
 * assigned employees used to cost several queries per user.
 */
class EnrollmentReconciler
{
    /** Rows per bulk write. */
    private const CHUNK = 500;

    public function reconcileAll(): void
    {
        Device::whereNotNull('payroll_device_code')
            ->pluck('no_sn')
            ->each(fn ($sn) => $this->reconcileDevice($sn));
    }

    /**
     * @return int number of commands newly queued
     */
    public function reconcileDevice(string $deviceSn, bool $forceUpdates = false): int
    {
        $device = Device::where('no_sn', $deviceSn)->first();
        if ($device === null || $device->payroll_device_code === null) {
            return 0; // not linked to a payroll device — nothing to enroll
        }

        $desired = $this->desiredUsers($device->payroll_device_code);
        $current = DeviceEnrollment::where('device_sn', $deviceSn)->get()->keyBy('pin');
        $pendingBodies = DeviceCommand::where('device_sn', $deviceSn)
            ->where('status', 'pending')
            ->pluck('body')
            ->flip();
        $now = now();

        $commands = [];
        $enrollments = [];

        // Add or update.
        foreach ($desired as $pin => $user) {
            $existing = $current->get($pin);
            $body = $this->updateCommand($user);
            $changed = $existing === null || $existing->name !== $user['name'] || $existing->card !== $user['card'];

            // Automatic reconciliation stays a cheap diff. The dashboard button
            // uses forceUpdates so an operator can safely re-send users after a
            // clock collected a command but never confirmed that it ran it.
            if (($changed || $forceUpdates) && ! $pendingBodies->has($body)) {
                $commands[] = $this->commandRow($deviceSn, $body, $now);
                $enrollments[] = [
                    'device_sn' => $deviceSn,
                    'pin' => $pin,
                    'name' => $user['name'],
                    'card' => $user['card'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $pendingBodies->put($body, true);
            }
        }

        // Remove anyone no longer assigned. Queued after the updates so the device
        // sees them in the same order it used to.
        $removed = [];
        foreach ($current as $pin => $enrollment) {
            if (! $desired->has($pin)) {
                $commands[] = $this->commandRow($deviceSn, "DATA DELETE USERINFO PIN={$pin}", $now);
                $removed[] = $pin;
            }
        }

        foreach (array_chunk($enrollments, self::CHUNK) as $chunk) {
            // created_at stays out of the update list so a re-pushed user keeps the
            // date it was first enrolled.
            DeviceEnrollment::upsert($chunk, ['device_sn', 'pin'], ['name', 'card', 'updated_at']);
        }

        foreach (array_chunk($removed, self::CHUNK) as $chunk) {
            DeviceEnrollment::where('device_sn', $deviceSn)->whereIn('pin', $chunk)->delete();
        }

        foreach (array_chunk($commands, self::CHUNK) as $chunk) {
            DeviceCommand::insert($chunk);
        }

        return count($commands);
    }

    /** @return Collection<string, array{pin:string,name:?string,card:?string}> */
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
        return "DATA UPDATE USERINFO PIN={$user['pin']}\tName={$user['name']}\tPri=0\tCard={$user['card']}";
    }

    /** @return array<string, mixed> */
    private function commandRow(string $deviceSn, string $body, $now): array
    {
        return [
            'device_sn' => $deviceSn,
            'body' => $body,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
