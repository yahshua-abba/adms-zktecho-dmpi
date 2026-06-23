<?php

namespace Tests\Support;

use App\Contracts\PayrollClient;
use App\Sync\PunchLog;
use App\Sync\PushResult;

/**
 * In-memory PayrollClient for tests. Records what was pushed and returns a
 * configurable result. By default it acks every punch it receives.
 */
class FakePayrollClient implements PayrollClient
{
    /** @var PunchLog[] */
    public array $pushed = [];

    /** @var array<int, array{id:int, company:string, chapa:string, name:?string, rfid:?string}> */
    public array $employees = [];

    /** @var array{devices: array, assignments: array} */
    public array $deviceInfo = ['devices' => [], 'assignments' => []];

    /** When set, pushLogs returns this instead of acking everything. */
    public ?PushResult $nextResult = null;

    public function pushLogs(array $logs): PushResult
    {
        $this->pushed = array_merge($this->pushed, $logs);

        if ($this->nextResult !== null) {
            return $this->nextResult;
        }

        $ids = array_map(fn (PunchLog $log) => $log->localId, $logs);

        return new PushResult(syncedLocalIds: $ids);
    }

    public function fetchEmployees(): array
    {
        return $this->employees;
    }

    public function fetchDeviceInfo(): array
    {
        return $this->deviceInfo;
    }
}
