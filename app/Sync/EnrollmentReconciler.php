<?php

namespace App\Sync;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\EmployeeMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    public const PERSON_QUEUED = 'queued';

    public const PERSON_ALREADY_WAITING = 'already_waiting';

    public const PERSON_NOT_ASSIGNED = 'not_assigned';

    public const PERSON_NOT_ELIGIBLE = 'not_eligible';

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

    /**
     * Queue one assigned employee for one physical clock.
     *
     * The clock row is locked while the duplicate check and writes happen, so
     * two quick clicks cannot put identical pending commands in its mailbox.
     * Assignment and employee mapping are checked again here rather than trusted
     * from the page: both can change during a payroll download after the page was
     * rendered.
     */
    public function reconcilePerson(string $deviceSn, int $payrollEmployeeId): string
    {
        return DB::transaction(function () use ($deviceSn, $payrollEmployeeId) {
            $device = Device::where('no_sn', $deviceSn)->lockForUpdate()->first();

            if ($device === null || $device->payroll_device_code === null) {
                return self::PERSON_NOT_ASSIGNED;
            }

            $assigned = DeviceAssignment::where('device_code', $device->payroll_device_code)
                ->where('payroll_employee_id', $payrollEmployeeId)
                ->exists();

            if (! $assigned) {
                return self::PERSON_NOT_ASSIGNED;
            }

            $employee = EmployeeMap::where('payroll_employee_id', $payrollEmployeeId)->first();
            if ($employee === null) {
                return self::PERSON_NOT_ELIGIBLE;
            }

            $user = [
                'pin' => $employee->device_pin,
                'name' => $employee->name,
                'card' => RfidConverter::toCard($employee->rfid),
            ];
            $body = $this->updateCommand($user);

            if (DeviceCommand::where('device_sn', $deviceSn)
                ->where('status', 'pending')
                ->where('body', $body)
                ->exists()) {
                return self::PERSON_ALREADY_WAITING;
            }

            DeviceEnrollment::updateOrCreate(
                ['device_sn' => $deviceSn, 'pin' => $user['pin']],
                ['name' => $user['name'], 'card' => $user['card']],
            );
            DeviceCommand::create($this->commandRow($deviceSn, $body, now()));

            return self::PERSON_QUEUED;
        });
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
