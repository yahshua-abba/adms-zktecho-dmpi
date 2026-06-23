<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\EmployeeMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceScreenTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function punch(array $attrs = []): Attendance
    {
        return Attendance::create(array_merge([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1',
            'employee_id' => '5_4968', 'timestamp' => now()->addSeconds($this->seq++), 'log_type' => 'in', 'is_sync' => false,
        ], $attrs));
    }

    public function test_attendance_page_renders_with_filter_bar(): void
    {
        $response = $this->get(route('devices.Attendance'));

        $response->assertOk();
        $response->assertSee('Sync status');
        $response->assertSee('id="attendance"', false);
    }

    public function test_ajax_returns_only_rows_matching_the_sync_filter(): void
    {
        $synced = $this->punch(['is_sync' => true]);
        $failed = $this->punch(['is_sync' => false, 'sync_error' => 'No Employee']);

        $response = $this->get(
            route('devices.Attendance', ['sync' => 'failed', 'draw' => 1, 'start' => 0, 'length' => 100]),
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($failed->id, $data[0]['id']);
        $this->assertStringContainsString('failed', $data[0]['sync_status']);
    }

    public function test_sync_now_button_pushes_pending_punches(): void
    {
        $this->app->instance(\App\Contracts\PayrollClient::class, $fake = new \Tests\Support\FakePayrollClient());
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213, 'name' => 'X']);
        $punch = $this->punch(['employee_id' => '5_4968', 'is_sync' => false]);

        $this->post('/attendance/sync')->assertRedirect(route('devices.Attendance'));

        $this->assertTrue($punch->fresh()->is_sync);
        $this->assertCount(1, $fake->pushed);
    }

    public function test_ajax_includes_device_details_inout_punched_received_and_synced(): void
    {
        \App\Models\Device::create(['no_sn' => 'DEV-IN', 'nama' => 'Admin IN', 'lokasi' => 'BUGO - Admin', 'direction' => 'in']);
        $this->punch(['sn' => 'DEV-IN', 'status1' => 0, 'timestamp' => '2026-06-18 08:00:00', 'is_sync' => true, 'sync_time' => '2026-06-18 11:00:00']);

        $response = $this->get(
            route('devices.Attendance', ['draw' => 1, 'start' => 0, 'length' => 100]),
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertStringContainsString('Admin IN', $row['device_display']);
        $this->assertStringContainsString('BUGO - Admin', $row['device_display']);
        $this->assertStringContainsString('IN', $row['inout']);
        $this->assertStringContainsString('2026-06-18 08:00', $row['timestamp']);  // punched at device
        $this->assertNotSame('—', $row['received_at']);                            // received by ADMS (created_at)
        $this->assertStringContainsString('2026-06-18 11:00', $row['synced_at']);  // synced to payroll
        $this->assertStringContainsString('synced', $row['sync_status']);          // status badge only
    }

    public function test_ajax_unsynced_punch_shows_dash_for_synced_at(): void
    {
        \App\Models\Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        $this->punch(['sn' => 'DEV-IN', 'is_sync' => false]);

        $row = $this->get(
            route('devices.Attendance', ['draw' => 1, 'start' => 0, 'length' => 100]),
            ['X-Requested-With' => 'XMLHttpRequest']
        )->json('data.0');

        $this->assertStringContainsString('—', $row['synced_at']);
        $this->assertNotSame('—', $row['received_at']); // still received by ADMS
    }

    public function test_ajax_inout_displays_the_frozen_log_type(): void
    {
        \App\Models\Device::create(['no_sn' => 'DEV-BOTH', 'direction' => 'both']);
        $this->punch(['sn' => 'DEV-BOTH', 'status1' => 1, 'log_type' => 'out']);

        $response = $this->get(
            route('devices.Attendance', ['draw' => 1, 'start' => 0, 'length' => 100]),
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        // Read-only badge, not an editable control.
        $this->assertStringContainsString('OUT', $response->json('data.0.inout'));
        $this->assertStringNotContainsString('<select', $response->json('data.0.inout'));
    }

    public function test_export_streams_all_punches_as_csv(): void
    {
        Device::create(['no_sn' => 'DEV-IN', 'nama' => 'Admin IN', 'lokasi' => 'BUGO - Admin', 'direction' => 'in']);
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213, 'name' => 'ABABA, Rubelyn']);
        $this->punch(['sn' => 'DEV-IN', 'employee_id' => '5_4968', 'is_sync' => true, 'sync_time' => '2026-06-18 11:00:00']);
        $this->punch(['sn' => 'DEV-IN', 'employee_id' => '5_9999', 'is_sync' => false]);

        $response = $this->get(route('attendance.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('attendances-', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('ID,"Punched at"', $csv);
        $this->assertStringContainsString('ABABA, Rubelyn', $csv);
        $this->assertStringContainsString('Admin IN', $csv);
        $this->assertStringContainsString('synced', $csv);
        $this->assertStringContainsString('pending', $csv);
        $this->assertStringContainsString('5_9999', $csv);
    }

    public function test_export_honors_the_sync_filter(): void
    {
        $this->punch(['is_sync' => true]);
        $this->punch(['is_sync' => false, 'sync_error' => 'No Employee', 'employee_id' => '5_FAILED']);

        $csv = $this->get(route('attendance.export', ['sync' => 'failed']))->streamedContent();

        $this->assertStringContainsString('5_FAILED', $csv);
        $this->assertStringContainsString('failed', $csv);
        // The synced punch must be excluded by the filter.
        $this->assertStringNotContainsString('5_4968', $csv);
    }

    public function test_ajax_resolves_employee_name_and_flags_unmapped(): void
    {
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213, 'name' => 'ABABA, Rubelyn']);
        $this->punch(['employee_id' => '5_4968']);
        $this->punch(['employee_id' => '5_9999']); // unmapped

        $response = $this->get(
            route('devices.Attendance', ['draw' => 1, 'start' => 0, 'length' => 100]),
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('ABABA, Rubelyn', $body);
        $this->assertStringContainsString('CHAPA 4968', $body);
        $this->assertStringContainsString('Payroll #48213', $body);
        $this->assertStringContainsString('unmapped', $body);
    }
}
