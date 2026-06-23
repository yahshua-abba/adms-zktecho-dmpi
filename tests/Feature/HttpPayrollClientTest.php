<?php

namespace Tests\Feature;

use App\Sync\HttpPayrollClient;
use App\Sync\PunchLog;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpPayrollClientTest extends TestCase
{
    private function client(): HttpPayrollClient
    {
        return new HttpPayrollClient('https://payroll.test/', 'svc@dmpi', 'secret');
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
}
