<?php

namespace App\Support;

/**
 * The shared "how many rows per page" options offered across every table in
 * the dashboard — Server Activity, Employees, Attendance, Devices, and the
 * Device Check-ins / Device Messages logs. One list so the dropdown reads the
 * same everywhere, capped at 500 so a page-size pick can't turn into an
 * accidental full-table dump.
 */
class PerPage
{
    public const OPTIONS = [10, 25, 50, 100, 250, 500];

    public const DEFAULT = 25;

    /** Snaps an untrusted value (e.g. straight off the query string) to one of OPTIONS, or DEFAULT. */
    public static function resolve(?int $requested): int
    {
        return in_array($requested, self::OPTIONS, true) ? $requested : self::DEFAULT;
    }
}
