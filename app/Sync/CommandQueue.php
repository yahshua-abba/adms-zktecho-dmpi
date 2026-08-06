<?php

namespace App\Sync;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Queries\DeviceQueue;
use Illuminate\Support\Facades\DB;

/**
 * Calling back instructions this server has queued for a clock but not yet
 * handed over.
 *
 * Exists because nothing else could undo a mistaken link. Re-pointing a reader
 * at the wrong payroll device makes the reconciler queue a removal for every
 * user on it, and correcting the link afterwards does not take those back — the
 * queue is a mailbox, not a setting. The reader collects and obeys whatever is
 * in it. Observed live: a reader pointed at an empty test device queued 1,249
 * deletions, and clearing the link left all 1,249 in place.
 *
 * Two rules, and both are load-bearing:
 *
 * 1. **Only `pending` is cancellable.** A `sent` instruction is already in the
 *    device's hands and nothing here can recall it. Deleting those rows would
 *    only destroy the record of what went out while changing nothing on the
 *    machine — it would make the screen lie about the damage rather than undo
 *    it.
 *
 * 2. **Cancelling an add must also forget that we sent that person.**
 *    EnrollmentReconciler writes `device_enrollment` at the moment it *queues*
 *    an add, not when the device takes it. So a cancelled add leaves a row
 *    claiming the person is on the clock; the next reconcile compares against
 *    it, finds nothing owed, and queues nothing — the person is silently absent
 *    from that reader for good. Dropping the enrollment row puts them back in
 *    the next run's diff. Cancelled removals need no such repair: the reconciler
 *    already deleted the enrollment row when it queued them, which is exactly
 *    the state "on the machine, no longer managed here" wants.
 */
class CommandQueue
{
    /**
     * Cancel queued instructions for a device.
     *
     * @param  int[]|null  $ids  specific commands, or null for every pending one
     * @return array{cancelled:int, skipped:int, requeued:int}
     *                                                         skipped = asked for but already handed to the device
     */
    public function cancel(Device $device, ?array $ids = null): array
    {
        $scope = fn () => DeviceCommand::where('device_sn', $device->no_sn)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids));

        // Counted before the delete, so the caller can tell an operator that part
        // of their selection was already gone rather than silently doing less
        // than they asked.
        $alreadySent = $ids === null
            ? 0
            : (clone $scope())->where('status', '!=', DeviceQueue::PENDING)->count();

        $candidates = (clone $scope())->where('status', DeviceQueue::PENDING)->get();

        if ($candidates->isEmpty()) {
            return ['cancelled' => 0, 'skipped' => $alreadySent, 'requeued' => 0];
        }

        $cancelled = 0;
        $requeued = 0;
        $raced = 0;

        DB::transaction(function () use ($device, $candidates, &$cancelled, &$requeued, &$raced) {
            $candidateIds = $candidates->pluck('id');

            // Guarded by status, not by id alone. `getrequest` flips rows from
            // pending to sent whenever the device polls — every 30s per device,
            // and this runs against queues thousands of rows long — so a row read
            // as pending a moment ago may be in the device's hands by now.
            // Deleting it on the strength of the earlier read is exactly the
            // silent damage rule 1 exists to prevent.
            $cancelled = DeviceCommand::whereIn('id', $candidateIds)
                ->where('status', DeviceQueue::PENDING)
                ->delete();

            // Which candidates the guard turned away. Read back rather than
            // locking the rows up front: `cancel all` on a wedged clock touches
            // thousands of rows, and holding those locks would stall the very
            // `getrequest` that is draining the queue.
            $survived = DeviceCommand::whereIn('id', $candidateIds)->pluck('id')->flip();
            $raced = $survived->count();

            // PINs whose *add* we actually called back — see rule 2. Narrowed to
            // the rows that really went: repairing the enrollment for a row still
            // on its way to the device would tell this server the person was
            // never sent while the device is being told to add them.
            $addPins = $candidates
                ->reject(fn (DeviceCommand $c) => $survived->has($c->id))
                ->filter(fn (DeviceCommand $c) => DeviceQueue::action($c->body) === DeviceQueue::ADD)
                ->map(fn (DeviceCommand $c) => DeviceQueue::pin($c->body))
                ->filter()
                ->unique()
                ->values();

            if ($addPins->isNotEmpty()) {
                $requeued = DeviceEnrollment::where('device_sn', $device->no_sn)
                    ->whereIn('pin', $addPins)
                    ->delete();
            }
        });

        // Rows that raced away are reported alongside the ones that were already
        // sent when we looked: from the operator's side they are the same fact —
        // asked for, not cancelled, now the device's business.
        return ['cancelled' => $cancelled, 'skipped' => $alreadySent + $raced, 'requeued' => $requeued];
    }
}
