<?php

namespace App\Sync;

use App\Contracts\PayrollClient;
use App\Models\Attendance;
use App\Models\EmployeeMap;
use App\Models\PinCollision;

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

        // Resolve the whole batch's PINs up front: one query each instead of one
        // lookup per punch. employee_id holds the device PIN = "{company}_{chapa}".
        $pins = $pending->pluck('employee_id')->map(fn ($pin) => (string) $pin)->unique()->all();
        $payrollIdByPin = EmployeeMap::whereIn('device_pin', $pins)->pluck('payroll_employee_id', 'device_pin');
        $contestedByPin = PinCollision::whereIn('device_pin', $pins)->get()->keyBy('device_pin');

        foreach ($pending as $attendance) {
            // IN/OUT was frozen onto the punch at arrival from the device's
            // direction; a null means the device had no direction set then.
            if ($attendance->log_type === null) {
                $this->flag($attendance, "Device {$attendance->sn} had no IN/OUT direction when this punch was recorded.");
                $localFailures++;

                continue;
            }

            $pin = (string) $attendance->employee_id;
            $payrollId = $payrollIdByPin[$pin] ?? null;
            if ($payrollId === null) {
                $this->flag($attendance, $this->unresolvedReason($pin, $contestedByPin->get($pin)));
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

    /**
     * Why a PIN didn't resolve. A contested PIN is deliberately left unmapped
     * (RosterSync parks it), so it lands here too — but "no mapping" would send an
     * operator hunting for a missing employee when the real problem is two of them
     * sharing one PIN, which is fixed somewhere else entirely.
     */
    private function unresolvedReason(string $pin, ?PinCollision $collision): string
    {
        if ($collision === null) {
            return "No employee mapping for device PIN {$pin}.";
        }

        $claimants = $collision->claimants ?? [];
        $ids = implode(', ', array_map(fn (array $c) => $c['payroll_employee_id'], $claimants));

        return "Device PIN {$pin} is claimed by ".count($claimants)." payroll employees ({$ids}) — "
            .'pick the right one under Employees > PIN conflicts, or fix the duplicate in DMPI.';
    }

    private function flag(Attendance $attendance, string $reason): void
    {
        $attendance->forceFill(['sync_error' => $reason])->save();
    }
}
