<?php

namespace App\Sync;

/**
 * One attendance punch, translated into the shape DMPI's /api/sync-logs/ expects.
 *
 * `localId` is the local Attendance id, echoed back by DMPI in its ack so we know
 * which row to mark synced. `employee` is the DMPI payroll Employee.id.
 */
class PunchLog
{
    public function __construct(
        public readonly int $localId,
        public readonly int $employee,
        public readonly string $date,     // Y-m-d
        public readonly string $logTime,  // H:i:s
        public readonly string $logType,  // "in" | "out"
        public readonly string $syncId,
    ) {
    }

    /** The per-log object inside the DMPI `log_list` payload. */
    public function toPayload(): array
    {
        return [
            'id' => $this->localId,
            'employee' => $this->employee,
            'date' => $this->date,
            'log_time' => $this->logTime,
            'log_type' => $this->logType,
            'sync_id' => $this->syncId,
            'from_biometrics' => true,
        ];
    }
}
