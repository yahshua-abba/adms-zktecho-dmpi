<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\EmployeeMap;
use App\Sync\AttendanceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePayrollClient;
use Tests\TestCase;

class AttendanceSyncTest extends TestCase
{
    use RefreshDatabase;

    private function map(array $attrs): void
    {
        EmployeeMap::create(array_merge(['company' => '5', 'chapa' => '4968'], $attrs));
    }

    public function test_pushes_unsynced_punch_and_marks_it_synced_when_acked(): void
    {
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        $this->map(['device_pin' => '5_4968', 'payroll_employee_id' => 48213, 'name' => 'Rubelyn']);
        $attendance = Attendance::create([
            'sn' => 'DEV-IN',
            'table' => 'ATTLOG',
            'stamp' => '9999',
            'employee_id' => '5_4968',
            'timestamp' => '2026-06-17 08:01:33',
            'log_type' => 'in',
            'is_sync' => false,
        ]);

        $payroll = new FakePayrollClient();
        (new AttendanceSync($payroll))->sync();

        $this->assertCount(1, $payroll->pushed);
        $log = $payroll->pushed[0];
        $this->assertSame(48213, $log->employee);
        $this->assertSame('in', $log->logType);
        $this->assertSame('2026-06-17', $log->date);
        $this->assertSame('08:01:33', $log->logTime);
        $this->assertSame('DEV-IN-'.$attendance->id, $log->syncId);

        $attendance->refresh();
        $this->assertTrue($attendance->is_sync);
        $this->assertNotNull($attendance->sync_time);
    }

    public function test_same_chapa_in_different_companies_resolves_to_distinct_employees(): void
    {
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        // Two companies reuse CHAPA 4968 — the composite PIN disambiguates them.
        $this->map(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213]);
        $this->map(['device_pin' => '7_4968', 'company' => '7', 'chapa' => '4968', 'payroll_employee_id' => 70001]);

        Attendance::create(['sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:00:00', 'log_type' => 'in', 'is_sync' => false]);
        Attendance::create(['sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '7_4968', 'timestamp' => '2026-06-17 08:01:00', 'log_type' => 'in', 'is_sync' => false]);

        (new AttendanceSync($payroll = new FakePayrollClient()))->sync();

        $this->assertSame([48213, 70001], array_map(fn ($l) => $l->employee, $payroll->pushed));
    }

    public function test_pushes_the_log_type_frozen_on_each_punch(): void
    {
        Device::create(['no_sn' => 'DEV-BOTH', 'direction' => 'both']);
        $this->map(['device_pin' => '5_4968', 'payroll_employee_id' => 48213]);
        // log_type was frozen at arrival; sync pushes it verbatim.
        $in = Attendance::create(['sn' => 'DEV-BOTH', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:00:00', 'log_type' => 'in', 'is_sync' => false]);
        $out = Attendance::create(['sn' => 'DEV-BOTH', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_4968', 'timestamp' => '2026-06-17 17:00:00', 'log_type' => 'out', 'is_sync' => false]);

        (new AttendanceSync($payroll = new FakePayrollClient()))->sync();

        $byId = collect($payroll->pushed)->keyBy('localId');
        $this->assertSame('in', $byId[$in->id]->logType);
        $this->assertSame('out', $byId[$out->id]->logType);
    }

    public function test_editing_device_direction_does_not_change_what_is_pushed(): void
    {
        $device = Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        $this->map(['device_pin' => '5_4968', 'payroll_employee_id' => 48213]);
        // Frozen as 'in' at arrival.
        Attendance::create(['sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:00:00', 'log_type' => 'in', 'is_sync' => false]);

        // Operator flips the device to OUT before the punch syncs.
        $device->update(['direction' => 'out']);
        (new AttendanceSync($payroll = new FakePayrollClient()))->sync();

        $this->assertSame('in', $payroll->pushed[0]->logType);
    }

    public function test_punch_with_unmapped_pin_is_not_pushed_and_is_flagged(): void
    {
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        $attendance = Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '9999',
            'employee_id' => '5_9999', 'timestamp' => '2026-06-17 08:01:33', 'log_type' => 'in', 'is_sync' => false,
        ]);

        (new AttendanceSync($payroll = new FakePayrollClient()))->sync();

        $this->assertCount(0, $payroll->pushed);
        $attendance->refresh();
        $this->assertFalse($attendance->is_sync);
        $this->assertNotNull($attendance->sync_error);
        $this->assertStringContainsString('employee', strtolower($attendance->sync_error));
    }

    public function test_punch_with_no_frozen_log_type_is_not_pushed_and_is_flagged(): void
    {
        Device::create(['no_sn' => 'DEV-X', 'direction' => null]);
        $this->map(['device_pin' => '5_4968', 'payroll_employee_id' => 48213]);
        // No direction was set when this punch arrived, so log_type is null.
        $attendance = Attendance::create([
            'sn' => 'DEV-X', 'table' => 'ATTLOG', 'stamp' => '9999',
            'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:01:33', 'log_type' => null, 'is_sync' => false,
        ]);

        (new AttendanceSync($payroll = new FakePayrollClient()))->sync();

        $this->assertCount(0, $payroll->pushed);
        $attendance->refresh();
        $this->assertFalse($attendance->is_sync);
        $this->assertStringContainsString('direction', strtolower($attendance->sync_error));
    }

    public function test_punch_rejected_by_payroll_is_left_unsynced_with_reason(): void
    {
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        $this->map(['device_pin' => '5_4968', 'payroll_employee_id' => 48213]);
        $attendance = Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '9999',
            'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:01:33', 'log_type' => 'in', 'is_sync' => false,
        ]);

        $payroll = new FakePayrollClient();
        $payroll->nextResult = new \App\Sync\PushResult(
            syncedLocalIds: [],
            failures: [['localId' => $attendance->id, 'errorCode' => 2, 'reason' => 'No Employee']],
        );
        (new AttendanceSync($payroll))->sync();

        $attendance->refresh();
        $this->assertFalse($attendance->is_sync);
        $this->assertSame('No Employee', $attendance->sync_error);
    }

    public function test_already_synced_punches_are_not_repushed(): void
    {
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        $this->map(['device_pin' => '5_4968', 'payroll_employee_id' => 48213]);
        Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '9999',
            'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:01:33', 'is_sync' => true,
        ]);

        (new AttendanceSync($payroll = new FakePayrollClient()))->sync();

        $this->assertCount(0, $payroll->pushed);
    }

    public function test_drains_all_pending_across_multiple_batches_in_one_run(): void
    {
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        $this->map(['device_pin' => '5_4968', 'payroll_employee_id' => 48213]);
        for ($i = 0; $i < 5; $i++) {
            Attendance::create([
                'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '9999',
                'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:0'.$i.':00', 'log_type' => 'in', 'is_sync' => false,
            ]);
        }

        (new AttendanceSync($payroll = new FakePayrollClient()))->sync(2);

        $this->assertCount(5, $payroll->pushed);
        $this->assertSame(5, Attendance::where('is_sync', true)->count());
    }

    public function test_unsyncable_rows_do_not_cause_an_infinite_loop(): void
    {
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        $this->map(['device_pin' => '5_4968', 'payroll_employee_id' => 48213]);
        $mapped = Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '9999',
            'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:00:00', 'log_type' => 'in', 'is_sync' => false,
        ]);
        $unmapped = Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '9999',
            'employee_id' => '5_9999', 'timestamp' => '2026-06-17 08:01:00', 'log_type' => 'in', 'is_sync' => false,
        ]);

        (new AttendanceSync($payroll = new FakePayrollClient()))->sync(1);

        $this->assertTrue($mapped->fresh()->is_sync);
        $this->assertFalse($unmapped->fresh()->is_sync);
        $this->assertNotNull($unmapped->fresh()->sync_error);
        $this->assertCount(1, $payroll->pushed);
    }

    public function test_pushes_multiple_punches_in_one_batch(): void
    {
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        Device::create(['no_sn' => 'DEV-OUT', 'direction' => 'out']);
        $this->map(['device_pin' => '5_4968', 'chapa' => '4968', 'payroll_employee_id' => 48213]);
        $this->map(['device_pin' => '5_9343', 'chapa' => '9343', 'payroll_employee_id' => 51234]);
        Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '9999',
            'employee_id' => '5_4968', 'timestamp' => '2026-06-17 08:01:33', 'log_type' => 'in', 'is_sync' => false,
        ]);
        Attendance::create([
            'sn' => 'DEV-OUT', 'table' => 'ATTLOG', 'stamp' => '9999',
            'employee_id' => '5_9343', 'timestamp' => '2026-06-17 17:05:00', 'log_type' => 'out', 'is_sync' => false,
        ]);

        (new AttendanceSync($payroll = new FakePayrollClient()))->sync();

        $this->assertCount(2, $payroll->pushed);
        $this->assertSame(['in', 'out'], array_map(fn ($l) => $l->logType, $payroll->pushed));
        $this->assertSame([48213, 51234], array_map(fn ($l) => $l->employee, $payroll->pushed));
        $this->assertSame(2, Attendance::where('is_sync', true)->count());
    }
}
