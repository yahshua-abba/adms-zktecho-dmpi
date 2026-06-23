<?php

namespace App\Health;

/**
 * Starts/inspects the Laravel scheduler worker (`schedule:work`) from inside the
 * app, so an operator can recover it from the Health page without a terminal.
 *
 * `schedule:work` is a long-running process; it dies whenever the container
 * restarts and is not part of container startup. start() launches it fully
 * detached (nohup + background) so it survives the web request that spawned it.
 */
class SchedulerControl
{
    public function isRunning(): bool
    {
        exec("pgrep -f 'artisan schedule:work'", $out, $code);

        return $code === 0 && ! empty($out);
    }

    public function start(): void
    {
        $artisan = escapeshellarg(base_path('artisan'));
        $log = escapeshellarg(storage_path('logs/scheduler.log'));

        // Detached so it outlives this web request (reparented to the container's init).
        exec("nohup php {$artisan} schedule:work >> {$log} 2>&1 &");
    }
}
