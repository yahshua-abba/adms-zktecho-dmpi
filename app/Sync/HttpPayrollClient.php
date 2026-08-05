<?php

namespace App\Sync;

use App\Contracts\PayrollClient;
use App\Exceptions\PayrollAuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

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
        private int $retries = 3,
        private int $retryBaseMs = 2000,
    ) {}

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
            // DMPI ships *un-assignments* in the same list: rows flagged is_active
            // false meaning "this person no longer belongs on this device". Neither
            // the full nor the incremental pull filters them server-side, so taking
            // every row as an assignment enrols people who were explicitly removed.
            // Observed live for Bugo: 28,788 of 56,158 rows were inactive.
            // (array_key_exists, not a truthy check: a payload that omits the field
            // entirely keeps the old behaviour rather than dropping everything.)
            if (array_key_exists('is_active', $row) && ! $row['is_active']) {
                continue;
            }

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

    private function authed(string $token): PendingRequest
    {
        return $this->base()->withHeaders(['Authorization' => 'Token '.$token]);
    }

    /**
     * Base request: the user-agent DMPI sniffs for timekeeper access, the long read
     * ceiling its bulk endpoints need, and backoff for transport failures.
     *
     * throw: false is deliberate. With throwing on, enabling retries makes Laravel
     * throw on *any* unsuccessful response, which would break both the 401 re-auth
     * path in send() and login()'s deliberate read of DMPI's error body. Retries
     * are driven purely by the when() callback instead.
     */
    private function base(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => $this->userAgent])
            ->connectTimeout(15)
            ->timeout($this->timeout)
            ->retry(
                max(1, $this->retries),
                fn (int $attempt) => (int) min($this->retryBaseMs * (2 ** ($attempt - 1)), 30000),
                fn (\Throwable $e) => $this->isTransient($e),
                throw: false,
            );
    }

    /**
     * Worth another go: the connection dropped, DMPI is overloaded (5xx), or it
     * asked us to slow down (429). A 401/403 is a decision rather than a blip —
     * and retrying a refused login is exactly how the account gets locked out.
     */
    private function isTransient(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return ! $this->isReadTimeout($e);
        }

        if ($e instanceof RequestException && $e->response !== null) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /**
     * A read timeout is not a blip worth repeating: the request already had the
     * full — and deliberately very generous — timeout budget, so retrying only
     * multiplies the wait. Three attempts at the 600s ceiling is half an hour of
     * silence, which is exactly what DMPI's read_device_info endpoint produced.
     *
     * cURL words a read timeout "Operation timed out"; a *connection* timeout
     * ("Connection timed out", against the 15s connect ceiling) is a genuine blip
     * and still gets another go.
     */
    private function isReadTimeout(\Throwable $e): bool
    {
        return stripos($e->getMessage(), 'Operation timed out') !== false;
    }

    private function authToken(): string
    {
        return $this->token ??= $this->login();
    }

    /**
     * Trade the configured credentials for an API token.
     *
     * Throws rather than returning an empty token on refusal: a blank token
     * still produces well-formed requests, so every caller would silently read
     * DMPI's error body as an empty roster/device list and treat a lockout as
     * "nothing to sync" (which then wiped the assignment table).
     */
    private function login(): string
    {
        $response = $this->base()
            ->post($this->url('api/api-auth/'), [
                'username' => $this->username,
                'password' => $this->password,
                'from_local_server' => true,
            ]);

        $token = $response->json('token');

        if (! is_string($token) || trim($token) === '') {
            throw PayrollAuthException::fromResponse($response);
        }

        return $token;
    }

    private function url(string $path): string
    {
        return rtrim($this->validatedBaseUrl(), '/').'/'.$path;
    }

    private function validatedBaseUrl(): string
    {
        $url = trim($this->baseUrl);

        if ($url === '') {
            throw new InvalidArgumentException('PAYROLL_URL is missing. Set it to the full DMPI URL, for example https://delmontepayroll.com.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('PAYROLL_URL must include http:// or https://.');
        }

        if (! parse_url($url, PHP_URL_HOST)) {
            throw new InvalidArgumentException('PAYROLL_URL must be a full DMPI URL, for example https://delmontepayroll.com.');
        }

        return $url;
    }
}
