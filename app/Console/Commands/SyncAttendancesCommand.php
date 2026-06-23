<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Sync\AttendanceSync;
use Illuminate\Console\Command;

class SyncAttendancesCommand extends Command
{
    protected $signature = 'payroll:sync-attendances';

    protected $description = 'Push unsynced attendance punches to the DMPI payroll app';

    public function handle(AttendanceSync $sync): int
    {
        try {
            $r = $sync->sync((int) config('payroll.batch_size'));
            ActivityLog::record(
                'attendance.sync',
                "Pushed punches to payroll: {$r['synced']} synced, {$r['failed']} failed.",
                $r['failed'] > 0 ? 'warning' : 'info',
                $r,
            );
            $this->info('Attendance sync complete.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            ActivityLog::record('attendance.sync', 'Attendance sync failed: '.$e->getMessage(), 'error');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
