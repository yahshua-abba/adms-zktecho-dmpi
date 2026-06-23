<?php

namespace App\Maintenance;

use Illuminate\Support\Facades\DB;

/**
 * Deletes raw contact logs older than a retention window.
 *
 * device_log/finger_log are diagnostic and grow without bound (a device_log
 * row roughly every 30s per device from handshakes alone), so they are aged
 * out. Attendance records are NOT pruned — those are the data of record.
 */
class LogPruner
{
    /** @return array{device_log:int, finger_log:int} rows deleted per table */
    public static function prune(int $days): array
    {
        $cutoff = now()->subDays($days);

        return [
            'device_log' => DB::table('device_log')->where('created_at', '<', $cutoff)->delete(),
            'finger_log' => DB::table('finger_log')->where('created_at', '<', $cutoff)->delete(),
        ];
    }
}
