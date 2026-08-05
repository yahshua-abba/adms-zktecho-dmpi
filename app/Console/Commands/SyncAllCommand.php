<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HoldsTheDmpiLock;
use App\Models\ActivityLog;
use App\Sync\DeviceInfoSync;
use App\Sync\EnrollmentReconciler;
use App\Sync\RosterSync;
use Illuminate\Console\Command;

/**
 * The whole DMPI pull in one run: roster, then devices + assignments, then
 * enrollment reconciliation. The dashboard's three Download buttons each launch
 * one stage instead; this is the safe way to run the lot by hand — nothing here
 * touches the web server.
 *
 * Like every download command it holds the shared lock for its whole run, so a
 * second run (or a scheduled one landing on top of a manual one) reports
 * "already running" rather than doubling the load on this box and on DMPI.
 */
class SyncAllCommand extends Command
{
    use HoldsTheDmpiLock;

    protected $signature = 'payroll:sync-all';

    protected $description = 'Pull the roster, devices and assignments from DMPI, then reconcile device enrollments';

    public function handle(RosterSync $roster, DeviceInfoSync $devices, EnrollmentReconciler $reconciler): int
    {
        ini_set('memory_limit', (string) config('payroll.memory_limit'));

        return $this->holdingTheDmpiLock('all', function ($run) use ($roster, $devices, $reconciler) {
            try {
                ActivityLog::record('dmpi.pull', 'DMPI sync started.');
                $report = fn (string $stage, ?int $done, ?int $total) => $run->toStage($stage, $done, $total);

                $result = $roster->sync($report);
                $devices->sync($report);
                $run->toStage('Updating the clocks', null, null);
                $reconciler->reconcileAll();

                $undecided = $result['contested'] - $result['resolved'];
                $message = "DMPI sync complete. Mapped {$result['mapped']} employees.";
                if ($undecided > 0) {
                    $message .= " {$undecided} device PIN(s) are claimed by more than one employee and are left unmapped — "
                        .'their punches will not sync until decided under Employees > PIN conflicts.';
                }

                ActivityLog::record('dmpi.pull', $message, $undecided > 0 ? 'error' : 'info');
                $run->finish('succeeded', $message);
                $this->info($message);

                return self::SUCCESS;
            } catch (\Throwable $e) {
                ActivityLog::record('dmpi.pull', 'DMPI sync failed: '.$e->getMessage(), 'error');
                $run->finish('failed', $e->getMessage());
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        });
    }
}
