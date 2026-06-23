<?php

namespace App\Sync;

/**
 * The outcome of pushing a batch of punches to DMPI, expressed in terms the
 * caller cares about: which local Attendance ids were accepted, and which were
 * rejected (with a reason).
 *
 * DMPI's quirky ack semantics — notably that error_code 1 means "already
 * exists, accept it" — are normalised away by the PayrollClient adapter before
 * this object is built, so accepted-duplicates appear in `syncedLocalIds`.
 */
class PushResult
{
    /**
     * @param  int[]  $syncedLocalIds
     * @param  array<int, array{localId:int, errorCode:int|null, reason:string}>  $failures
     */
    public function __construct(
        public readonly array $syncedLocalIds = [],
        public readonly array $failures = [],
    ) {
    }
}
