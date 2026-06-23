<?php

namespace App\Sync;

/**
 * The single source of truth for a punch's IN/OUT, so the sync engine and the
 * Attendance screen never disagree.
 *
 * Dedicated devices use their fixed direction; a "both" device takes the
 * direction from the punch's own state code (status1): even = in, odd = out
 * (the ZKTeco convention). An unconfigured device (null direction) yields null.
 */
class LogType
{
    public static function resolve(?string $direction, int $state): ?string
    {
        return match ($direction) {
            'in', 'out' => $direction,
            'both' => $state % 2 === 1 ? 'out' : 'in',
            default => null,
        };
    }
}
