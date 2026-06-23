<?php

namespace App\Sync;

use App\Contracts\PayrollClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The production PayrollClient — talks to the live DMPI payroll app.
 *
 * Hides everything DMPI-specific behind the PayrollClient seam: token login
 * (with the YP_TIMEKEEPER user-agent DMPI sniffs for timekeeper access),
 * automatic re-authentication when a token expires (401), the
 * {from_biometrics, log_list} push payload, and DMPI's ack quirk where
 * error_code 1 means "already exists, accept it" (folded into syncedLocalIds).
 */
class HttpPayrollClient implements PayrollClient
{
    private ?string $token = null;

    public function __construct(
        private string $baseUrl,
        private string $username,
        private string $password,
        private string $userAgent = 'YP_TIMEKEEPER',
        private int $timeout = 600,
    ) {
    }

    public function pushLogs(array $logs): PushResult
    {
        $payload = [
            'from_biometrics' => true,
            'log_list' => array_map(fn (PunchLog $log) => $log->toPayload(), $logs),
        ];

        $data = $this->send(
            fn (string $token) => $this->authed($token)->post($this->url('api/sync-logs/'), $payload)
        )->json() ?? [];

        $synced = $data['logs_successfully_sync'] ?? [];
        $failures = [];
        foreach ($data['logs_not_sync'] ?? [] as $rejected) {
            $code = $rejected['error_code'] ?? null;
            if ($code === 1) {
                $synced[] = $rejected['id']; // accepted duplicate
                continue;
            }
            $failures[] = [
                'localId' => (int) $rejected['id'],
                'errorCode' => $code,
                'reason' => $rejected['reason'] ?? 'Rejected by payroll.',
            ];
        }

        return new PushResult(
            syncedLocalIds: array_map('intval', $synced),
            failures: $failures,
        );
    }

    public function fetchEmployees(): array
    {
        $employees = $this->send(
            fn (string $token) => $this->authed($token)->post($this->url('api/v2/read_employees/'), ['from_rfid' => true])
        )->json('employees') ?? [];

        $mapped = array_map(function (array $employee) {
            // DMPI returns a derived `code` (= employeeid or employeeid2); fall back
            // to the raw fields. This is the CHAPA half of the composite device PIN.
            $chapa = $employee['code'] ?? ($employee['employeeid'] ?: ($employee['employeeid2'] ?? null));

            if (empty($chapa) || ! isset($employee['company'])) {
                return null; // can't build a "{company}_{chapa}" key without both
            }

            return [
                'id' => (int) $employee['id'],
                'company' => (string) $employee['company'],
                'chapa' => (string) $chapa,
                'name' => trim(($employee['lastname'] ?? '').', '.($employee['firstname'] ?? ''), ', '),
                'rfid' => $employee['rfid'] ?? null,
            ];
        }, $employees);

        return array_values(array_filter($mapped));
    }

    public function fetchDeviceInfo(): array
    {
        $data = $this->send(
            fn (string $token) => $this->authed($token)->post($this->url('api/v2/read_device_info/'), ['from_rfid' => true])
        )->json() ?? [];

        $devices = $data['timekeeper_devices'] ?? [];
        $codeById = [];
        foreach ($devices as $device) {
            $codeById[$device['id'] ?? null] = $device['code'] ?? null;
        }

        $assignments = [];
        foreach ($data['timekeeper_device_employees'] ?? [] as $row) {
            $employeeId = $row['employee']['id'] ?? null;
            $deviceRef = $row['timekeeper_device'] ?? null;
            $code = is_array($deviceRef)
                ? ($deviceRef['code'] ?? ($codeById[$deviceRef['id'] ?? null] ?? null))
                : ($codeById[$deviceRef] ?? null);

            if ($employeeId && $code) {
                $assignments[] = ['employee_id' => (int) $employeeId, 'device_code' => (string) $code];
            }
        }

        return [
            'devices' => array_values(array_filter(array_map(function (array $device) {
                if (empty($device['code'])) {
                    return null;
                }

                return [
                    'code' => (string) $device['code'],
                    'name' => $device['device_location']['name'] ?? ($device['device_location']['location'] ?? null),
                ];
            }, $devices))),
            'assignments' => $assignments,
        ];
    }

    /** Run a request; on 401 drop the cached token, re-authenticate, retry once. */
    private function send(callable $make): Response
    {
        $response = $make($this->authToken());

        if ($response->status() === 401) {
            $this->token = null;
            $response = $make($this->authToken());
        }

        return $response;
    }

    private function authed(string $token)
    {
        return Http::withHeaders([
            'Authorization' => 'Token '.$token,
            'User-Agent' => $this->userAgent,
        ])->connectTimeout(15)->timeout($this->timeout);
    }

    private function authToken(): string
    {
        return $this->token ??= $this->login();
    }

    private function login(): string
    {
        return (string) Http::withHeaders(['User-Agent' => $this->userAgent])
            ->connectTimeout(15)->timeout($this->timeout)
            ->post($this->url('api/api-auth/'), [
                'username' => $this->username,
                'password' => $this->password,
                'from_local_server' => true,
            ])
            ->json('token');
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.$path;
    }
}
