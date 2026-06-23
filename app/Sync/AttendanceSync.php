<?php

namespace App\Sync;

use App\Contracts\PayrollClient;
use App\Models\Attendance;
use App\Models\EmployeeMap;

/**
 * Pushes unsynced attendance punches to DMPI.
 *
 * For each pending punch it reads the IN/OUT (log_type) frozen onto the row at
 * arrival and resolves the employee's payroll id, shapes a PunchLog, hands the
 * batch to the PayrollClient, then marks accepted punches synced. Punches it
 * cannot resolve are left unsynced with a recorded reason so they retry once the
 * gap is fixed.
 */
class AttendanceSync
{
    public function __construct(private PayrollClient $payroll)
    {
    }

    /**
     * Drain all currently-pending punches, pushing them in batches.
     *
     * Uses an id cursor rather than re-querying `is_sync = false` each loop:
     * punches we can't resolve (unmapped PIN, no device direction, payroll
     * rejection) stay is_sync=false on purpose, so a plain "while pending" loop
     * would re-select them forever. Advancing past the highest id seen means
     * every pending row is attempted exactly once per run; the unsyncable ones
     * are retried on the next run (when the gap may have been fixed).
     */
    /** @return array{synced:int, failed:int} */
    public function sync(int $batchSize = 50): array
    {
        $lastId = 0;
        $synced = 0;
        $failed = 0;

        while (true) {
            $pending = Attendance::where('is_sync', false)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($pending->isEmpty()) {
                return ['synced' => $synced, 'failed' => $failed];
            }

            $lastId = $pending->last()->id;
            $result = $this->pushBatch($pending);
            $synced += $result['synced'];
            $failed += $result['failed'];
        }
    }

    /** @return array{synced:int, failed:int} */
    private function pushBatch($pending): array
    {
        $logs = [];
        foreach ($pending as $attendance) {
            // IN/OUT was frozen onto the punch at arrival from the device's
            // direction; a null means the device had no direction set then.
            if ($attendance->log_type === null) {
                $this->flag($attendance, "Device {$attendance->sn} had no IN/OUT direction when this punch was recorded.");
                continue;
            }

            // employee_id holds the device PIN = "{company}_{chapa}".
            $payrollId = EmployeeMap::where('device_pin', (string) $attendance->employee_id)
                ->value('payroll_employee_id');
            if ($payrollId === null) {
                $this->flag($attendance, "No employee mapping for device PIN {$attendance->employee_id}.");
                continue;
            }

            $logs[] = new PunchLog(
                localId: $attendance->id,
                employee: (int) $payrollId,
                date: $attendance->timestamp->format('Y-m-d'),
                logTime: $attendance->timestamp->format('H:i:s'),
                logType: $attendance->log_type,
                syncId: $attendance->sn.'-'.$attendance->id,
            );
        }

        if (empty($logs)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $result = $this->payroll->pushLogs($logs);

        if (! empty($result->syncedLocalIds)) {
            Attendance::whereIn('id', $result->syncedLocalIds)->update([
                'is_sync' => true,
                'sync_time' => now(),
                'sync_error' => null,
            ]);
        }

        foreach ($result->failures as $failure) {
            $attendance = $pending->firstWhere('id', $failure['localId']);
            if ($attendance !== null) {
                $this->flag($attendance, $failure['reason']);
            }
        }

        return ['synced' => count($result->syncedLocalIds), 'failed' => count($result->failures)];
    }

    private function flag(Attendance $attendance, string $reason): void
    {
        $attendance->forceFill(['sync_error' => $reason])->save();
    }
}

