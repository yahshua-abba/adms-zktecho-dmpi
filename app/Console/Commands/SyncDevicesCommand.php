<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HoldsTheDmpiLock;
use App\Models\ActivityLog;
use App\Models\DeviceAssignment;
use App\Models\PayrollDevice;
use App\Sync\DeviceInfoSync;
use App\Sync\EnrollmentReconciler;
use Illuminate\Console\Command;

class SyncDevicesCommand extends Command
{
    use HoldsTheDmpiLock;

    protected $signature = 'payroll:sync-devices {--only= : Limit to "devices" (the clock list) or "assignments" (who belongs on which clock)}';

    protected $description = 'Pull the device list and device-employee assignments from DMPI';

    public function handle(DeviceInfoSync $sync, EnrollmentReconciler $reconciler): int
    {
        // DMPI's read_device_info can be large (cluster-wide); give the parse headroom.
        ini_set('memory_limit', (string) config('payroll.memory_limit'));

        $only = $this->option('only');

        if ($only !== null && ! in_array($only, ['devices', 'assignments'], true)) {
            $this->error('--only must be "devices" or "assignments".');

            return self::INVALID;
        }

        // 'device-info', not 'all': this pulls the clock list and the assignments,
        // and nothing else. Recording it as 'all' made the progress banner claim it
        // was downloading everything, and would now let a device pull vouch for the
        // freshness of a roster it never touched.
        return $this->holdingTheDmpiLock($only ?? 'device-info', function ($run) use ($sync, $reconciler, $only) {
            try {
                $report = fn (string $stage, ?int $done, ?int $total) => $run->toStage($stage, $done, $total);

                match ($only) {
                    'devices' => $sync->syncDevices($report),
                    'assignments' => $sync->syncAssignments($report),
                    default => $sync->sync($report),
                };

                // Changed assignments mean nothing until the clocks are told. Left on
                // its own, downloading assignments is invisible to every reader until
                // the 15-minute reconcile runs — and that depends on the scheduler,
                // which is started by hand here and dies on a container restart.
                // Reconciling inline keeps "downloaded" and "applied" from drifting.
                if ($only !== 'devices') {
                    $run->toStage('Updating the clocks', null, null);
                    $reconciler->reconcileAll();
                }

                $message = 'Device info pull complete. Devices: '.PayrollDevice::count()
                    .', assignments: '.DeviceAssignment::count().'.';
                ActivityLog::record('devices.sync', $message);
                $run->finish('succeeded', $message);
                $this->info($message);

                return self::SUCCESS;
            } catch (\Throwable $e) {
                ActivityLog::record('devices.sync', 'Device info pull failed: '.$e->getMessage(), 'error');
                $run->finish('failed', $e->getMessage());
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        });
    }
}
