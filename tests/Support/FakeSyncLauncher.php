<?php

namespace Tests\Support;

use App\Sync\DmpiSyncLauncher;

/**
 * A launcher that records instead of spawning a real detached process. Keeps the
 * lock behaviour of the real one, so "already running" still exercises the lock.
 */
class FakeSyncLauncher extends DmpiSyncLauncher
{
    public int $started = 0;

    /** @var string[] which parts were launched, in order */
    public array $parts = [];

    public function start(string $part): void
    {
        $this->started++;
        $this->parts[] = $part;
    }
}
