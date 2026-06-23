<?php

namespace App\Contracts;

use App\Sync\PunchLog;
use App\Sync\PushResult;

/**
 * The seam between this edge server and the DMPI payroll app.
 *
 * Everything a caller must know lives here: push punches, get the employee
 * roster. Authentication (token acquisition, the YP_TIMEKEEPER user-agent,
 * re-auth on expiry) and DMPI's payload/ack quirks are hidden inside the
 * implementing adapter — callers never see a token.
 */
interface PayrollClient
{
    /**
     * Push a batch of punches to DMPI and report which were accepted/rejected.
     *
     * @param  PunchLog[]  $logs
     */
    public function pushLogs(array $logs): PushResult;

    /**
     * Fetch the employee roster used to map device PINs to payroll ids.
     *
     * `company` + `chapa` form the composite device PIN ("{company}_{chapa}")
     * that disambiguates employees who share a CHAPA across companies. `rfid`
     * is the card pushed to devices during auto-enrollment.
     *
     * @return array<int, array{id:int, company:string, chapa:string, name:?string, rfid:?string}>
     */
    public function fetchEmployees(): array;

    /**
     * Fetch the DMPI device list and device-employee assignments, used to
     * auto-enroll the right employees onto the right devices.
     *
     * @return array{
     *   devices: array<int, array{code:string, name:?string}>,
     *   assignments: array<int, array{employee_id:int, device_code:string}>
     * }
     */
    public function fetchDeviceInfo(): array;
}
