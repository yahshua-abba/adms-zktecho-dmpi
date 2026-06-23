<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\DeviceCommand;
use App\Sync\EnrollmentReconciler;
use Illuminate\Console\Command;

class ReconcileEnrollmentsCommand extends Command
{
    protected $signature = 'payroll:reconcile-enrollments';

    protected $description = 'Queue device commands to match each device user list to its DMPI assignments';

    public function handle(EnrollmentReconciler $reconciler): int
    {
        try {
            $before = DeviceCommand::count();
            $reconciler->reconcileAll();
            $queued = DeviceCommand::count() - $before;
            ActivityLog::record('enrollment.reconcile', "Enrollment reconcile complete. Commands queued: {$queued}.");
            $this->info('Enrollments reconciled.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            ActivityLog::record('enrollment.reconcile', 'Enrollment reconcile failed: '.$e->getMessage(), 'error');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
