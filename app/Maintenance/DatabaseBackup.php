<?php

namespace App\Maintenance;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dumps the whole database to storage/app/backups before anything destructive.
 *
 * The Clear database action is irreversible and takes the attendance table with
 * it — punches are the data of record and are deliberately never pruned, so a
 * wipe can destroy work that was never sent to payroll. Taking the backup
 * automatically means it does not depend on somebody remembering.
 *
 * The password is passed through the environment rather than the command line:
 * arguments are visible to anyone who can run `ps`.
 */
class DatabaseBackup
{
    /**
     * @return ?string absolute path to the dump, or null when the driver is not
     *                 MySQL (there is nothing to shell out to)
     *
     * @throws RuntimeException when a dump was attempted and failed
     */
    public static function create(string $reason = 'manual'): ?string
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'mysql') {
            return null;
        }

        $directory = storage_path('app/backups');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the backup directory.');
        }

        $path = $directory.'/'.$reason.'-'.now()->format('Y-m-d-His').'.sql';

        $process = new Process([
            'mysqldump',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            '--single-transaction',
            '--no-tablespaces',
            $config['database'] ?? '',
        ], timeout: 600, env: ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new RuntimeException('Could not open the backup file for writing.');
        }

        try {
            $process->run(function ($type, $buffer) use ($handle) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful() || filesize($path) === 0) {
            @unlink($path);

            throw new RuntimeException('The backup failed: '.trim($process->getErrorOutput() ?: 'mysqldump produced nothing.'));
        }

        return $path;
    }
}
