<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\EmployeeMap;
use App\Sync\RosterSync;
use Illuminate\Console\Command;

class SyncRosterCommand extends Command
{
    protected $signature = 'payroll:sync-roster';

    protected $description = 'Pull the employee roster from DMPI and upsert the device-PIN map';

    public function handle(RosterSync $sync): int
    {
        // DMPI's read_employees returns the whole cluster; give the parse headroom.
        ini_set('memory_limit', '2048M');

        try {
            $sync->sync();
            ActivityLog::record('roster.sync', 'Roster pull complete. Mapped employees: '.EmployeeMap::count().'.');
            $this->info('Roster sync complete.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            ActivityLog::record('roster.sync', 'Roster pull failed: '.$e->getMessage(), 'error');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
