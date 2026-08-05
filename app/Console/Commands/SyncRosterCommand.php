<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HoldsTheDmpiLock;
use App\Models\ActivityLog;
use App\Models\EmployeeMap;
use App\Sync\RosterSync;
use Illuminate\Console\Command;

class SyncRosterCommand extends Command
{
    use HoldsTheDmpiLock;

    protected $signature = 'payroll:sync-roster';

    protected $description = 'Pull the employee roster from DMPI and upsert the device-PIN map';

    public function handle(RosterSync $sync): int
    {
        // DMPI's read_employees returns the whole cluster in one response, so the
        // parse needs headroom — but a bounded amount. See config('payroll.memory_limit').
        ini_set('memory_limit', (string) config('payroll.memory_limit'));

        return $this->holdingTheDmpiLock('employees', function ($run) use ($sync) {
            try {
                $result = $sync->sync(fn (string $stage, ?int $done, ?int $total) => $run->toStage($stage, $done, $total));
                $summary = 'Roster pull complete. Mapped employees: '.EmployeeMap::count().'.';
                ActivityLog::record('roster.sync', $summary);
                $run->finish('succeeded', $summary);
                $this->info('Roster sync complete.');

                // Contested PINs are deliberately left unmapped, so their punches stop
                // syncing until someone decides. That has to be loud, not a silent
                // count buried in a success message.
                $undecided = $result['contested'] - $result['resolved'];
                if ($undecided > 0) {
                    ActivityLog::record(
                        'roster.sync',
                        "{$undecided} device PIN(s) are claimed by more than one payroll employee and are left unmapped. "
                        .'Punches on those PINs will not sync until the conflict is resolved under Employees > PIN conflicts.',
                        'error',
                    );
                    $this->warn("{$undecided} contested device PIN(s) left unmapped — see Employees > PIN conflicts.");
                }

                return self::SUCCESS;
            } catch (\Throwable $e) {
                ActivityLog::record('roster.sync', 'Roster pull failed: '.$e->getMessage(), 'error');
                $run->finish('failed', $e->getMessage());
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        });
    }
}
