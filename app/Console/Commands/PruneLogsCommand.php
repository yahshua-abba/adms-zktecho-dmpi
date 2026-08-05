<?php

namespace App\Console\Commands;

use App\Maintenance\LogPruner;
use App\Models\ActivityLog;
use Illuminate\Console\Command;

class PruneLogsCommand extends Command
{
    protected $signature = 'logs:prune
        {--days= : Override the routine retention window in days}
        {--error-days= : Override how long warnings and errors are kept}';

    protected $description = 'Delete log rows older than the retention window';

    public function handle(): int
    {
        // `??`, not `?:`. An explicit `--days=0` means "clear it out now", and `?:`
        // read that as "not given" and quietly used the 30-day default instead —
        // the command reporting a successful prune while ignoring what it was told.
        $days = (int) ($this->option('days') ?? config('adms.log_retention_days'));
        $errorDays = (int) ($this->option('error-days') ?? config('adms.error_retention_days'));

        $deleted = LogPruner::prune($days, $errorDays);
        $total = array_sum($deleted);

        // Per table rather than as one number. "Deleted 2.1M rows" tells an operator
        // nothing; which table grew is the part worth seeing, and this job's output
        // is the only place that shows up before the disk does.
        foreach ($deleted as $table => $count) {
            $this->line(sprintf('  %-22s %8d', $table, $count));
        }

        $message = "Pruned {$total} log row(s): routine older than {$days} days, "
            ."warnings and errors older than {$errorDays} days.";

        // Only worth a Server Activity line when it actually deleted something.
        // A nightly "deleted nothing" note would be a row that next month's run has
        // to delete — the log tidying itself up after its own tidying.
        if ($total > 0) {
            ActivityLog::record('logs.prune', $message, 'info', $deleted);
        }

        $this->info($message);

        return self::SUCCESS;
    }
}
