<?php

namespace Tests\Feature;

use App\Exceptions\EmptyRosterException;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\EmployeeMap;
use App\Sync\DeviceInfoSync;
use App\Sync\EnrollmentReconciler;
use App\Sync\RosterSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakePayrollClient;
use Tests\TestCase;

/**
 * The sync stages write in chunks rather than a row at a time. These cover the
 * things that change when you stop going through Eloquent per row: payloads
 * bigger than one chunk, duplicate rows that updateOrCreate used to absorb
 * silently, and the query count actually coming down.
 */
class BulkSyncWritesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array> a roster spanning more than one chunk */
    private function largeRoster(int $count): array
    {
        $employees = [];
        for ($i = 1; $i <= $count; $i++) {
            $employees[] = ['id' => 100000 + $i, 'company' => '5', 'chapa' => (string) $i, 'name' => "EMP {$i}", 'rfid' => (string) $i];
        }

        return $employees;
    }

    public function test_roster_larger_than_one_chunk_is_written_completely(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->largeRoster(1200); // > the 500-row chunk

        (new RosterSync($payroll))->sync();

        $this->assertSame(1200, EmployeeMap::count());
        $this->assertSame(100001, (int) EmployeeMap::where('device_pin', '5_1')->value('payroll_employee_id'));
        $this->assertSame(101200, (int) EmployeeMap::where('device_pin', '5_1200')->value('payroll_employee_id'));
    }

    public function test_roster_write_no_longer_costs_two_queries_per_employee(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->largeRoster(1000);

        DB::enableQueryLog();
        (new RosterSync($payroll))->sync();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Row-at-a-time was ~2 queries per employee (a select then a write) = ~2000.
        $this->assertLessThan(30, $queries, "expected a handful of bulk writes, ran {$queries} queries");
        $this->assertSame(1000, EmployeeMap::count());
    }

    public function test_rerunning_the_roster_updates_in_place_rather_than_duplicating(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->largeRoster(600);
        (new RosterSync($payroll))->sync();

        $payroll->employees[0]['name'] = 'RENAMED';
        (new RosterSync($payroll))->sync();

        $this->assertSame(600, EmployeeMap::count());
        $this->assertSame('RENAMED', EmployeeMap::where('device_pin', '5_1')->value('name'));
    }

    public function test_empty_roster_is_refused_rather_than_acted_on(): void
    {
        EmployeeMap::create(['device_pin' => '5_1', 'company' => '5', 'chapa' => '1', 'payroll_employee_id' => 1]);

        $payroll = new FakePayrollClient;
        $payroll->employees = [];

        try {
            (new RosterSync($payroll))->sync();
            $this->fail('Expected EmptyRosterException.');
        } catch (EmptyRosterException $e) {
            $this->assertStringContainsString('refusing', $e->getMessage());
        }

        $this->assertSame(1, EmployeeMap::count());
    }

    public function test_duplicate_assignment_pairs_in_the_payload_do_not_break_the_insert(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->deviceInfo = [
            'devices' => [['code' => 'C1', 'name' => 'Gate 1']],
            'assignments' => [
                ['employee_id' => 48213, 'device_code' => 'C1'],
                ['employee_id' => 48213, 'device_code' => 'C1'], // repeat — unique index would reject it
                ['employee_id' => 48214, 'device_code' => 'C1'],
            ],
        ];

        (new DeviceInfoSync($payroll))->sync();

        $this->assertSame(2, DeviceAssignment::count());
        $this->assertDatabaseHas('device_assignments', ['device_code' => 'C1', 'payroll_employee_id' => 48213]);
        $this->assertDatabaseHas('device_assignments', ['device_code' => 'C1', 'payroll_employee_id' => 48214]);
    }

    public function test_assignment_payload_larger_than_one_chunk_is_written_completely(): void
    {
        $assignments = [];
        for ($i = 1; $i <= 1200; $i++) {
            $assignments[] = ['employee_id' => 200000 + $i, 'device_code' => 'C1'];
        }

        $payroll = new FakePayrollClient;
        $payroll->deviceInfo = ['devices' => [['code' => 'C1', 'name' => 'Gate 1']], 'assignments' => $assignments];

        (new DeviceInfoSync($payroll))->sync();

        $this->assertSame(1200, DeviceAssignment::count());
    }

    public function test_reconciler_queues_one_command_per_user_across_chunks(): void
    {
        Device::create(['no_sn' => 'DEV-1', 'direction' => 'in', 'payroll_device_code' => 'C1']);

        $payroll = new FakePayrollClient;
        $payroll->employees = $this->largeRoster(900);
        (new RosterSync($payroll))->sync();

        foreach (EmployeeMap::pluck('payroll_employee_id') as $id) {
            DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => $id]);
        }

        (new EnrollmentReconciler)->reconcileDevice('DEV-1');

        $this->assertSame(900, DeviceCommand::where('device_sn', 'DEV-1')->count());
        $this->assertSame(900, DeviceEnrollment::where('device_sn', 'DEV-1')->count());
    }

    public function test_reconciler_is_a_no_op_when_nothing_changed(): void
    {
        Device::create(['no_sn' => 'DEV-1', 'direction' => 'in', 'payroll_device_code' => 'C1']);

        $payroll = new FakePayrollClient;
        $payroll->employees = $this->largeRoster(600);
        (new RosterSync($payroll))->sync();
        foreach (EmployeeMap::pluck('payroll_employee_id') as $id) {
            DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => $id]);
        }

        $reconciler = new EnrollmentReconciler;
        $reconciler->reconcileDevice('DEV-1');
        $this->assertSame(600, DeviceCommand::count());

        // Second pass: the device already matches, so nothing new should be queued.
        $reconciler->reconcileDevice('DEV-1');
        $this->assertSame(600, DeviceCommand::count(), 'an unchanged device must not be re-pushed');
    }

    public function test_reconciler_queues_deletes_for_users_no_longer_assigned(): void
    {
        Device::create(['no_sn' => 'DEV-1', 'direction' => 'in', 'payroll_device_code' => 'C1']);
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_999', 'name' => 'GONE', 'card' => null]);

        (new EnrollmentReconciler)->reconcileDevice('DEV-1');

        $this->assertSame(0, DeviceEnrollment::where('device_sn', 'DEV-1')->count());
        $this->assertSame(1, DeviceCommand::where('body', 'like', 'DATA DELETE USERINFO PIN=5_999%')->count());
    }
}
