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
    public const RUNNING = 'running';

    public const STOPPED = 'stopped';

    /**
     * We asked and could not get an answer — `exec()` disabled by php.ini, or no
     * `pgrep` on the image.
     *
     * A third state rather than folding this into "stopped", because the two want
     * opposite handling: "stopped" is a red light and an invitation to restart,
     * while "unknown" means our own instrument is broken and must not be reported
     * as an outage or acted on by the watchdog. Guessing "stopped" here would
     * have the dashboard cry wolf on every box without pgrep, and would have the
     * watchdog launch a second scheduler alongside a perfectly healthy one.
     */
    public const UNKNOWN = 'unknown';

    /**
     * Cached for the life of the request. The monitoring page, the health card
     * and the watchdog all ask within one request, and shelling out three times
     * to learn the same thing is waste.
     */
    private ?string $state = null;

    public function processState(): string
    {
        return $this->state ??= $this->lookUpProcessState();
    }

    public function isRunning(): bool
    {
        return $this->processState() === self::RUNNING;
    }

    public function start(): void
    {
        $this->state = null;

        exec($this->launchCommand());
    }

    /**
     * The shell line that launches the scheduler.
     *
     * The `cd` is load-bearing, and its absence was a silent outage. `schedule:work`
     * does not run the jobs itself — every minute it spawns `php artisan schedule:run`,
     * built from Laravel's hardcoded *relative* `ARTISAN_BINARY` ('artisan') and
     * started without a working directory, so it inherits ours. Launched from a web
     * request that is the document root, where there is no `artisan`: the scheduler
     * came up, pgrep found it, the dashboard said "Running", and every single minute
     * it logged `Could not open input file: artisan` into a file nobody reads. Not one
     * job ran. Starting from the project root is what makes the child resolvable.
     *
     * Separate from start() so a test can assert on it — what it does when run is
     * precisely what a test must not do.
     */
    public function launchCommand(): string
    {
        $root = escapeshellarg(base_path());
        $log = escapeshellarg(storage_path('logs/scheduler.log'));

        // Detached so it outlives this web request (reparented to the container's init).
        return "cd {$root} && nohup php artisan schedule:work >> {$log} 2>&1 &";
    }

    private function lookUpProcessState(): string
    {
        if (! $this->canRunCommands()) {
            return self::UNKNOWN;
        }

        // The bracket is load-bearing. `pgrep -f` matches whole command lines, and
        // PHP's exec() runs this through `sh -c "pgrep -f '...'"` — whose own
        // command line contains the pattern. A plain 'artisan schedule:work' therefore
        // matched its own invocation and returned true whether or not a scheduler was
        // alive, so the Health page read "Running" on a box where nothing had been
        // scheduled for months. Writing it as wor[k] means the shell's command line
        // holds a literal "[k]", which the regex "wor[k]" cannot match.
        $out = [];
        $code = null;
        exec("pgrep -f 'artisan schedule:wor[k]'", $out, $code);

        if ($code === 0 && ! empty($out)) {
            return self::RUNNING;
        }

        // pgrep's contract: 0 = matched, 1 = nothing matched, anything else is an
        // error — including 127 for "no such command". Only the documented "nothing
        // matched" is allowed to mean the scheduler is down.
        return $code === 1 ? self::STOPPED : self::UNKNOWN;
    }

    /** Whether shelling out is possible at all on this install. */
    private function canRunCommands(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('exec', $disabled, true);
    }
}
