<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PayrollCall;
use App\Models\SyncRun;
use App\Support\PerPage;
use App\Sync\DmpiSyncLauncher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Live view of DMPI downloads, and the ability to stop one.
 *
 * Before this, a download reported "requested" and then, up to ten minutes
 * later, "failed" — with nothing in between. An operator watching an idle-looking
 * page pressed the button again, and (until the lock was fixed) started a second
 * concurrent pull against DMPI's production server. Showing the work is what
 * stops that happening.
 */
class SyncStatusController extends Controller
{
    /** Polled by the page every couple of seconds. */
    public function status()
    {
        $run = SyncRun::liveOrClosed();

        if ($run === null) {
            $last = SyncRun::latest('id')->first();

            return response()->json([
                'running' => false,
                'last' => $last === null ? null : [
                    'what' => $last->describe(),
                    'status' => $last->status,
                    'message' => $last->message,
                    'seconds' => $last->elapsedSeconds(),
                    'finished_at' => $last->finished_at?->toDateTimeString(),
                ],
            ]);
        }

        return response()->json([
            'running' => true,
            'what' => $run->describe(),
            'stage' => $run->stage,
            // Null percent is meaningful, not missing: while we are waiting on DMPI
            // there is genuinely nothing to measure, and the bar shows as indeterminate.
            'percent' => $run->percent(),
            'done' => $run->done,
            'total' => $run->total,
            'seconds' => $run->elapsedSeconds(),
            'calls' => $run->calls()->orderByDesc('id')->limit(5)->get()->map(fn (PayrollCall $c) => [
                'endpoint' => $c->endpoint,
                'outcome' => $c->outcome,
                'status_code' => $c->status_code,
                'duration' => $c->duration(),
                'size' => $c->size(),
                'error' => $c->error,
            ]),
        ]);
    }

    /**
     * Stop the running download.
     *
     * The expensive part is a single blocking read from DMPI, so there is no
     * checkpoint at which the process could notice a polite request to stop —
     * killing it is the only thing that actually interrupts a ten-minute wait.
     * A killed process runs no cleanup, so the lock is force-released and the run
     * closed here rather than by the process itself.
     */
    public function stop()
    {
        $run = SyncRun::current();

        if ($run === null) {
            return back()->with('error', 'Nothing is running.');
        }

        if ($run->looksLikeOurProcess()) {
            // Ask first, insist second. Mid-write work is inside a transaction, so
            // an abrupt end rolls back rather than leaving half a table.
            exec('kill -TERM '.escapeshellarg((string) $run->pid));
            usleep(300_000);

            if ($run->processIsAlive()) {
                exec('kill -KILL '.escapeshellarg((string) $run->pid));
                usleep(200_000);
            }
        }

        // Verify rather than assume. A signal we are not permitted to send fails
        // silently — a download started from a root shell cannot be killed by the
        // web server, which runs as a different user. Reporting "stopped" while it
        // carried on hammering DMPI would be worse than admitting the failure, and
        // releasing the lock would let a second download start alongside it.
        if ($run->processIsAlive()) {
            return back()->with(
                'error',
                "Couldn't stop {$run->describe()} — process {$run->pid} is still running and did not respond to being told to stop. "
                .'It was most likely started from a terminal by a different user; stop it there.'
            );
        }

        Cache::lock(DmpiSyncLauncher::LOCK)->forceRelease();
        $run->finish('cancelled', 'Stopped from the dashboard.');
        ActivityLog::record('dmpi.pull', "{$run->describe()} was stopped from the dashboard.", 'warning');

        return back()->with('success', $run->describe().' was stopped.');
    }

    /** The log of actual calls to DMPI. */
    public function calls(Request $request)
    {
        $perPage = PerPage::resolve($request->has('per_page') ? (int) $request->query('per_page') : null);

        $calls = PayrollCall::query()
            ->when($request->query('outcome'), fn ($q, $outcome) => $q->where('outcome', $outcome))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        return view('logs.payroll-calls', [
            'calls' => $calls,
            'outcome' => $request->query('outcome'),
            'running' => SyncRun::liveOrClosed(),
        ]);
    }
}
