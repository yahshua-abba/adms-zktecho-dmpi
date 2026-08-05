<?php

namespace Tests\Feature;

use App\Exceptions\PayrollAuthException;
use App\Sync\HttpPayrollClient;
use App\Sync\PunchLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class HttpPayrollClientTest extends TestCase
{
    /**
     * Retries are on in production with a real backoff; here the base wait is 0 so
     * the suite exercises the retry *logic* without actually sleeping through it.
     */
    private function client(int $retries = 3): HttpPayrollClient
    {
        return new HttpPayrollClient('https://payroll.test/', 'svc@dmpi', 'secret', retries: $retries, retryBaseMs: 0);
    }

    public function test_push_logs_authenticates_then_sends_token_payload_and_parses_acks(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => 'TKN123']),
            'payroll.test/api/sync-logs/' => Http::response([
                'logs_successfully_sync' => [1],
                'logs_not_sync' => [
                    ['id' => 2, 'error_code' => 1, 'reason' => 'Sync ID already exists.'],
                    ['id' => 3, 'error_code' => 2, 'reason' => 'No Employee'],
                ],
                'has_error' => true,
            ]),
        ]);

        $result = $this->client()->pushLogs([
            new PunchLog(1, 48213, '2026-06-17', '08:01:33', 'in', 'DEV-IN-1'),
            new PunchLog(2, 51234, '2026-06-17', '08:02:00', 'in', 'DEV-IN-2'),
            new PunchLog(3, 0, '2026-06-17', '08:03:00', 'in', 'DEV-IN-3'),
        ]);

        // error_code 1 (id 2) is accepted; only error_code 2 (id 3) is a failure.
        $this->assertEqualsCanonicalizing([1, 2], $result->syncedLocalIds);
        $this->assertCount(1, $result->failures);
        $this->assertSame(3, $result->failures[0]['localId']);
        $this->assertSame('No Employee', $result->failures[0]['reason']);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/api/sync-logs/')
                && $request->header('Authorization')[0] === 'Token TKN123'
                && $request->header('User-Agent')[0] === 'YP_TIMEKEEPER'
                && $request['from_biometrics'] === true
                && $request['log_list'][0]['employee'] === 48213
                && $request['log_list'][0]['log_type'] === 'in'
                && $request['log_list'][0]['sync_id'] === 'DEV-IN-1';
        });
    }

    public function test_missing_payroll_url_fails_with_actionable_message(): void
    {
        $client = new HttpPayrollClient('', 'svc@dmpi', 'secret');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PAYROLL_URL is missing');

        $client->fetchEmployees();
    }

    public function test_payroll_url_must_include_scheme(): void
    {
        $client = new HttpPayrollClient('payroll.test', 'svc@dmpi', 'secret');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PAYROLL_URL must include http:// or https://.');

        $client->fetchEmployees();
    }

    public function test_fetch_employees_maps_company_and_chapa_and_skips_rows_without_chapa(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => 'TKN123']),
            'payroll.test/api/v2/read_employees/' => Http::response([
                'employees' => [
                    ['id' => 48213, 'company' => 5, 'code' => '4968', 'employeeid' => '4968', 'firstname' => 'Rubelyn', 'lastname' => 'Ababa'],
                    ['id' => 6, 'company' => 5, 'code' => null, 'employeeid' => null, 'employeeid2' => null, 'firstname' => 'No', 'lastname' => 'Chapa'],
                ],
            ]),
        ]);

        $employees = $this->client()->fetchEmployees();

        $this->assertCount(1, $employees);
        $this->assertSame(48213, $employees[0]['id']);
        $this->assertSame('5', $employees[0]['company']);
        $this->assertSame('4968', $employees[0]['chapa']);
        $this->assertSame('Ababa, Rubelyn', $employees[0]['name']);
        $this->assertArrayHasKey('rfid', $employees[0]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v2/read_employees/')
            && $request['from_rfid'] === true);
    }

    public function test_fetch_device_info_parses_devices_and_assignments(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => 'TKN123']),
            'payroll.test/api/v2/read_device_info/' => Http::response([
                'timekeeper_devices' => [
                    ['id' => 1, 'code' => 'C1', 'device_location' => ['name' => 'Gate 1']],
                ],
                'timekeeper_device_employees' => [
                    ['employee' => ['id' => 48213], 'timekeeper_device' => ['id' => 1, 'code' => 'C1']],
                ],
            ]),
        ]);

        $info = $this->client()->fetchDeviceInfo();

        $this->assertSame('C1', $info['devices'][0]['code']);
        $this->assertSame('Gate 1', $info['devices'][0]['name']);
        $this->assertSame(48213, $info['assignments'][0]['employee_id']);
        $this->assertSame('C1', $info['assignments'][0]['device_code']);
    }

    public function test_inactive_assignments_are_unassignments_not_assignments(): void
    {
        // Shape taken from a live Bugo pull: DMPI returns removals in the same list,
        // flagged is_active false. Half the rows were removals.
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => 'TKN123']),
            'payroll.test/api/v2/read_device_info/' => Http::response([
                'timekeeper_devices' => [
                    ['id' => 96, 'code' => 'Admin IN', 'device_location' => ['name' => 'Admin']],
                ],
                'timekeeper_device_employees' => [
                    ['id' => 1, 'is_active' => true, 'employee' => ['id' => 48213], 'timekeeper_device' => ['id' => 96, 'code' => 'Admin IN']],
                    ['id' => 2, 'is_active' => false, 'employee' => ['id' => 99999], 'timekeeper_device' => ['id' => 96, 'code' => 'Admin IN']],
                ],
            ]),
        ]);

        $info = $this->client()->fetchDeviceInfo();

        $this->assertCount(1, $info['assignments'], 'an un-assigned employee must not be enrolled');
        $this->assertSame(48213, $info['assignments'][0]['employee_id']);
        // The device itself still comes through — it's the person who was removed.
        $this->assertSame('Admin IN', $info['devices'][0]['code']);
    }

    public function test_assignments_without_an_is_active_field_are_still_honoured(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => 'TKN123']),
            'payroll.test/api/v2/read_device_info/' => Http::response([
                'timekeeper_devices' => [['id' => 1, 'code' => 'C1']],
                'timekeeper_device_employees' => [
                    ['employee' => ['id' => 48213], 'timekeeper_device' => ['id' => 1, 'code' => 'C1']],
                ],
            ]),
        ]);

        $this->assertCount(1, $this->client()->fetchDeviceInfo()['assignments']);
    }

    public function test_login_refusal_throws_instead_of_reporting_an_empty_roster(): void
    {
        // DMPI answers 404 with a JSON body when an account is locked out. The old
        // code read the missing token as '' and every caller saw an empty list.
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response([
                'success' => false,
                'title' => "You've reached the maximum login attempt",
                'message' => 'Kindly inform the administrator.',
            ], 404),
        ]);

        $this->expectException(PayrollAuthException::class);
        $this->expectExceptionMessage("You've reached the maximum login attempt");

        $this->client()->fetchEmployees();
    }

    public function test_login_refusal_surfaces_status_and_falls_back_to_the_body(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response('Bad gateway', 502),
        ]);

        try {
            $this->client()->fetchDeviceInfo();
            $this->fail('Expected PayrollAuthException.');
        } catch (PayrollAuthException $e) {
            $this->assertStringContainsString('HTTP 502', $e->getMessage());
            $this->assertStringContainsString('Bad gateway', $e->getMessage());
        }
    }

    public function test_blank_token_is_treated_as_a_refusal(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => '  ']),
        ]);

        $this->expectException(PayrollAuthException::class);

        $this->client()->fetchDeviceInfo();
    }

    public function test_reauthenticates_when_token_expires(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::sequence()
                ->push(['token' => 'OLD'])
                ->push(['token' => 'NEW']),
            'payroll.test/api/sync-logs/' => Http::sequence()
                ->push([], 401)
                ->push(['logs_successfully_sync' => [1]], 200),
        ]);

        $result = $this->client()->pushLogs([
            new PunchLog(1, 48213, '2026-06-17', '08:01:33', 'in', 'DEV-IN-1'),
        ]);

        $this->assertSame([1], $result->syncedLocalIds);
        // Logged in twice (initial OLD, then NEW after the 401).
        Http::assertSentCount(4); // 2 auth + 2 sync-logs
    }

    public function test_a_server_error_is_retried_and_then_succeeds(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => 'TKN123']),
            'payroll.test/api/v2/read_employees/' => Http::sequence()
                ->push('upstream busy', 503)
                ->push('upstream busy', 503)
                ->push(['employees' => [
                    ['id' => 48213, 'company' => 5, 'code' => '4968', 'firstname' => 'Rubelyn', 'lastname' => 'Ababa'],
                ]], 200),
        ]);

        $employees = $this->client()->fetchEmployees();

        $this->assertCount(1, $employees);
        Http::assertSentCount(4); // 1 auth + 3 attempts
    }

    public function test_rate_limiting_is_retried(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => 'TKN123']),
            'payroll.test/api/v2/read_device_info/' => Http::sequence()
                ->push('slow down', 429)
                ->push(['timekeeper_devices' => [['id' => 1, 'code' => 'C1']]], 200),
        ]);

        $info = $this->client()->fetchDeviceInfo();

        $this->assertSame('C1', $info['devices'][0]['code']);
        Http::assertSentCount(3); // 1 auth + 2 attempts
    }

    public function test_a_refused_login_is_not_retried(): void
    {
        // Hammering a refused login is how the account gets locked out — and DMPI
        // does lock out ("You've reached the maximum login attempt").
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['detail' => 'Invalid credentials'], 401),
        ]);

        try {
            $this->client()->fetchEmployees();
            $this->fail('Expected PayrollAuthException.');
        } catch (PayrollAuthException $e) {
            // expected
        }

        Http::assertSentCount(1);
    }

    public function test_a_forbidden_response_is_not_retried(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => 'TKN123']),
            'payroll.test/api/v2/read_employees/' => Http::response('no timekeeper rights', 403),
        ]);

        $this->assertSame([], $this->client()->fetchEmployees());

        Http::assertSentCount(2); // 1 auth + 1 attempt, no repeats
    }

    public function test_a_read_timeout_is_not_retried(): void
    {
        // Observed for real against DMPI's read_device_info: the call sat past its
        // 600s ceiling, then retrying restarted the whole 600s wait. Three attempts
        // is half an hour of silence, so a timed-out read gets exactly one go.
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out after 600000 milliseconds with 0 bytes received');
        });

        try {
            $this->client(3)->fetchDeviceInfo();
            $this->fail('Expected ConnectionException.');
        } catch (ConnectionException $e) {
            // expected
        }

        $this->assertSame(1, $attempts, 'a read timeout must not be repeated');
    }

    public function test_a_connection_timeout_is_still_retried(): void
    {
        // Failing to *reach* DMPI at all is cheap (15s ceiling) and often transient.
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Connection timed out after 15000 milliseconds');
        });

        try {
            $this->client(3)->fetchDeviceInfo();
            $this->fail('Expected ConnectionException.');
        } catch (ConnectionException $e) {
            // expected
        }

        $this->assertSame(3, $attempts);
    }

    public function test_retries_are_bounded_and_the_last_response_is_returned(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => 'TKN123']),
            'payroll.test/api/v2/read_employees/' => Http::response('still down', 500),
        ]);

        // Exhausted retries return the failed response rather than throwing, so the
        // caller's own empty-payload guards decide what to do about it.
        $this->assertSame([], $this->client(3)->fetchEmployees());

        Http::assertSentCount(4); // 1 auth + 3 attempts, then it gives up
    }
}
