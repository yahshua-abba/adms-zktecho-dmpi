<?php

namespace App\Console\Commands;

use App\Maintenance\LogPruner;
use Illuminate\Console\Command;

class PruneLogsCommand extends Command
{
    protected $signature = 'logs:prune {--days= : Override the retention window in days}';

    protected $description = 'Delete raw device_log / finger_log rows older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('adms.log_retention_days'));

        $deleted = LogPruner::prune($days);

        $this->info("Pruned device_log: {$deleted['device_log']}, finger_log: {$deleted['finger_log']} (older than {$days} days).");

        return self::SUCCESS;
    }
}
