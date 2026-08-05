<?php

namespace App\Sync;

use App\Models\PayrollCall;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * Records every outbound HTTP call to DMPI, so an operator can see what is
 * actually happening instead of inferring it from a ten-minute silence.
 *
 * ONLY METADATA IS STORED — method, endpoint path, status, size, duration.
 * Never a request or response body. The login body carries the payroll password
 * and the response bodies carry thousands of employees' names, addresses and
 * card numbers; the safest redaction is not holding the data at all.
 *
 * The endpoint is reduced to its path for the same reason: query strings are a
 * classic place for credentials to leak into logs.
 *
 * Timing pairs a RequestSending with the ResponseReceived that follows it. Sync
 * commands are single-threaded CLI processes making one call at a time, so a
 * single "last started" marker is sufficient; a retried request simply records
 * one row per attempt, which is exactly what you want to see when diagnosing.
 */
class PayrollCallRecorder
{
    private ?float $startedAt = null;

    private ?int $openCallId = null;

    /** Set by whichever sync command is running, so calls attach to their run. */
    private static ?int $runId = null;

    public static function attributeTo(?int $syncRunId): void
    {
        self::$runId = $syncRunId;
    }

    public function sending(RequestSending $event): void
    {
        if (! $this->isPayroll($event->request->url())) {
            return;
        }

        $this->startedAt = microtime(true);

        // Written the moment the request goes out, not when it comes back. A call
        // that never returns — the ten-minute device read, a process killed by the
        // Stop button — otherwise leaves no trace at all, which is precisely the
        // call an operator most needs to see.
        $this->openCallId = PayrollCall::create([
            'sync_run_id' => self::$runId,
            'method' => $event->request->method(),
            'endpoint' => $this->pathOf($event->request->url()),
            'outcome' => 'pending',
            'created_at' => now(),
        ])->id;
    }

    public function received(ResponseReceived $event): void
    {
        if (! $this->isPayroll($event->request->url())) {
            return;
        }

        $status = $event->response->status();

        $this->record(
            method: $event->request->method(),
            url: $event->request->url(),
            statusCode: $status,
            bytes: strlen((string) $event->response->body()),
            outcome: $status >= 200 && $status < 300 ? 'ok' : 'http_error',
            error: $status >= 400 ? "HTTP {$status}" : null,
        );
    }

    public function failed(ConnectionFailed $event): void
    {
        if (! $this->isPayroll($event->request->url())) {
            return;
        }

        // The exception message is cURL's own wording ("Operation timed out
        // after 600000 milliseconds"), which is precisely the useful part.
        $message = method_exists($event, 'exception') || property_exists($event, 'exception')
            ? ($event->exception?->getMessage() ?? 'Connection failed')
            : 'Connection failed';

        $this->record(
            method: $event->request->method(),
            url: $event->request->url(),
            statusCode: null,
            bytes: null,
            outcome: 'failed',
            error: mb_substr($message, 0, 500),
        );
    }

    private function record(string $method, string $url, ?int $statusCode, ?int $bytes, string $outcome, ?string $error): void
    {
        $duration = $this->startedAt === null
            ? null
            : (int) round((microtime(true) - $this->startedAt) * 1000);

        $outcomeAttributes = [
            'status_code' => $statusCode,
            'response_bytes' => $bytes,
            'duration_ms' => $duration,
            'outcome' => $outcome,
            'error' => $error,
        ];

        // Close out the row opened in sending(). Falls back to writing a fresh row
        // if the pairing was lost, so an outcome is never silently dropped.
        $closed = $this->openCallId !== null
            && PayrollCall::whereKey($this->openCallId)->update($outcomeAttributes) > 0;

        if (! $closed) {
            PayrollCall::create($outcomeAttributes + [
                'sync_run_id' => self::$runId,
                'method' => $method,
                'endpoint' => $this->pathOf($url),
                'created_at' => now(),
            ]);
        }

        $this->startedAt = null;
        $this->openCallId = null;
    }

    /**
     * Path only — never the query string, which is a classic place for a credential
     * to leak into a log. The health check pings the bare host, which has no path
     * at all; that shows as "/" rather than printing the whole URL.
     */
    private function pathOf(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: '/');
    }

    /** Only our payroll host is recorded — not every HTTP call the app makes. */
    private function isPayroll(string $url): bool
    {
        $base = (string) config('payroll.base_url');

        if (trim($base) === '') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $payrollHost = parse_url($base, PHP_URL_HOST);

        return $host !== null && $payrollHost !== null && strcasecmp($host, $payrollHost) === 0;
    }
}
