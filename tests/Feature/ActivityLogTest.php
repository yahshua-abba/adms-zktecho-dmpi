<?php

namespace Tests\Feature;

use App\Contracts\PayrollClient;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\EmployeeMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePayrollClient;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_creates_an_entry_with_context(): void
    {
        ActivityLog::record('attendance.sync', 'Pushed 3, failed 1', 'warning', ['synced' => 3, 'failed' => 1]);

        $log = ActivityLog::first();
        $this->assertSame('attendance.sync', $log->event);
        $this->assertSame('warning', $log->level);
        $this->assertSame(3, $log->context['synced']);
    }

    public function test_sync_attendances_command_records_activity(): void
    {
        $fake = new FakePayrollClient();
        $this->app->instance(PayrollClient::class, $fake);
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213, 'name' => 'X']);
        Attendance::create(['sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_4968', 'timestamp' => now(), 'log_type' => 'in', 'is_sync' => false]);

        $this->artisan('payroll:sync-attendances')->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['event' => 'attendance.sync']);
        $this->assertStringContainsString('1 synced', ActivityLog::where('event', 'attendance.sync')->first()->message);
    }

    public function test_activity_page_renders_and_filters_by_level(): void
    {
        ActivityLog::record('roster.sync', 'Roster ok', 'info');
        ActivityLog::record('attendance.sync', 'Sync failed: boom', 'error');

        $this->get('/activity')->assertOk()->assertSee('Roster ok')->assertSee('Sync failed: boom');
        $this->get('/activity?level=error')->assertOk()->assertSee('Sync failed: boom')->assertDontSee('Roster ok');
    }
}
