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
    public function __construct(private PayrollClient $payroll) {}

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
                // Manually skipped from the Attendance screen — left out of the
                // automatic/scheduled drain on purpose. syncIds() below still lets
                // an operator hand-pick one of these and push it anyway.
                ->where('sync_excluded', false)
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

    /**
     * Push a specific, operator-picked set of punches (the "sync selected" action
     * on the Attendance screen). Unlike sync(), this intentionally ignores
     * sync_excluded — hand-picking a punch is an explicit override of a standing
     * "skip" mark. Already-synced ids are silently ignored.
     */
    /** @return array{synced:int, failed:int} */
    public function syncIds(array $ids, int $batchSize = 50): array
    {
        $synced = 0;
        $failed = 0;

        $pending = Attendance::whereIn('id', $ids)
            ->where('is_sync', false)
            ->orderBy('id')
            ->get();

        foreach ($pending->chunk($batchSize) as $chunk) {
            $result = $this->pushBatch($chunk);
            $synced += $result['synced'];
            $failed += $result['failed'];
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    /** @return array{synced:int, failed:int} */
    private function pushBatch($pending): array
    {
        $logs = [];
        // Punches we reject before they ever reach payroll still count as failures —
        // otherwise a batch where every row is unmapped reports "0 failed" and the
        // caller (the command's log line, the "Sync selected" notice) stays silent
        // about work that didn't happen.
        $localFailures = 0;

        foreach ($pending as $attendance) {
            // IN/OUT was frozen onto the punch at arrival from the device's
            // direction; a null means the device had no direction set then.
            if ($attendance->log_type === null) {
                $this->flag($attendance, "Device {$attendance->sn} had no IN/OUT direction when this punch was recorded.");
                $localFailures++;

                continue;
            }

            // employee_id holds the device PIN = "{company}_{chapa}".
            $payrollId = EmployeeMap::where('device_pin', (string) $attendance->employee_id)
                ->value('payroll_employee_id');
            if ($payrollId === null) {
                $this->flag($attendance, "No employee mapping for device PIN {$attendance->employee_id}.");
                $localFailures++;

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
            return ['synced' => 0, 'failed' => $localFailures];
        }

        $result = $this->payroll->pushLogs($logs);

        if (! empty($result->syncedLocalIds)) {
            Attendance::whereIn('id', $result->syncedLocalIds)->update([
                'is_sync' => true,
                'sync_time' => now(),
                'sync_error' => null,
                // syncIds() can hand-pick a punch carrying a standing "skip" mark.
                // Once it's actually synced the mark no longer applies — clearing it
                // keeps sync_excluded meaningful only for unsynced rows.
                'sync_excluded' => false,
            ]);
        }

        foreach ($result->failures as $failure) {
            $attendance = $pending->firstWhere('id', $failure['localId']);
            if ($attendance !== null) {
                $this->flag($attendance, $failure['reason']);
            }
        }

        return [
            'synced' => count($result->syncedLocalIds),
            'failed' => $localFailures + count($result->failures),
        ];
    }

    private function flag(Attendance $attendance, string $reason): void
    {
        $attendance->forceFill(['sync_error' => $reason])->save();
    }
}
