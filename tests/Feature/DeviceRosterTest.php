<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\EmployeeMap;
use App\Models\PinCollision;
use App\Queries\DeviceRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceRosterTest extends TestCase
{
    use RefreshDatabase;

    /** A device linked to payroll code C1, with one employee assigned and enrolled. */
    private function device(?string $code = 'C1'): Device
    {
        return Device::create(['no_sn' => 'DEV-1', 'nama' => 'Main Gate', 'payroll_device_code' => $code]);
    }

    private function employee(string $pin, int $payrollId, string $name, ?string $rfid = null): EmployeeMap
    {
        [$company, $chapa] = explode('_', $pin);

        return EmployeeMap::create([
            'device_pin' => $pin, 'company' => $company, 'chapa' => $chapa,
            'payroll_employee_id' => $payrollId, 'name' => $name, 'rfid' => $rfid,
        ]);
    }

    public function test_counts_report_what_was_sent_and_what_payroll_assigns(): void
    {
        $device = $this->device();
        $this->employee('5_1', 101, 'ALPHA, Ann');
        $this->employee('5_2', 102, 'BRAVO, Ben');
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 101]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 102]);
        // Only one of the two has actually been pushed to the clock.
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_1', 'name' => 'ALPHA, Ann']);

        $counts = DeviceRoster::counts(collect([$device]))['DEV-1'];

        $this->assertSame(1, $counts['on_clock']);
        $this->assertSame(2, $counts['assigned']);
        $this->assertSame(0, $counts['blocked']);
        $this->assertTrue($counts['linked']);
    }

    public function test_counts_flag_assigned_employees_with_no_employee_record(): void
    {
        $device = $this->device();
        $this->employee('5_1', 101, 'ALPHA, Ann');
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 101]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 999]); // not on the roster

        $counts = DeviceRoster::counts(collect([$device]))['DEV-1'];

        $this->assertSame(2, $counts['assigned']);
        $this->assertSame(1, $counts['blocked']);
    }

    public function test_counts_include_changes_still_queued_for_the_device(): void
    {
        $device = $this->device();
        DeviceCommand::create(['device_sn' => 'DEV-1', 'body' => 'DATA UPDATE USERINFO PIN=5_1', 'status' => 'pending']);
        DeviceCommand::create(['device_sn' => 'DEV-1', 'body' => 'DATA DELETE USERINFO PIN=5_2', 'status' => 'sent']);
        DeviceCommand::create(['device_sn' => 'DEV-1', 'body' => 'DATA UPDATE USERINFO PIN=5_3', 'status' => 'done']);

        $counts = DeviceRoster::counts(collect([$device]))['DEV-1'];

        // Only what the device still owes us — a completed command is not pending.
        $this->assertSame(2, $counts['queued']);
    }

    public function test_two_clocks_on_the_same_payroll_code_each_report_that_codes_assignments(): void
    {
        $a = Device::create(['no_sn' => 'DEV-A', 'payroll_device_code' => 'C1']);
        $b = Device::create(['no_sn' => 'DEV-B', 'payroll_device_code' => 'C1']);
        $this->employee('5_1', 101, 'ALPHA, Ann');
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 101]);
        DeviceEnrollment::create(['device_sn' => 'DEV-A', 'pin' => '5_1', 'name' => 'ALPHA, Ann']);

        $counts = DeviceRoster::counts(collect([$a, $b]));

        $this->assertSame(1, $counts['DEV-A']['assigned']);
        $this->assertSame(1, $counts['DEV-A']['on_clock']);
        $this->assertSame(1, $counts['DEV-B']['assigned']);
        $this->assertSame(0, $counts['DEV-B']['on_clock']);
    }

    public function test_breakdown_separates_sent_pending_add_and_pending_removal(): void
    {
        $device = $this->device();
        $this->employee('5_1', 101, 'ALPHA, Ann');
        $this->employee('5_2', 102, 'BRAVO, Ben');
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 101]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 102]);
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_1', 'name' => 'ALPHA, Ann']);
        // Sent to the clock, but payroll no longer assigns them.
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_9', 'name' => 'ZULU, Zed']);

        $roster = DeviceRoster::forDevice($device);
        $byPin = collect($roster['people']->items())->keyBy('pin');

        $this->assertSame(DeviceRoster::ON_CLOCK, $byPin['5_1']['status']);
        $this->assertSame(DeviceRoster::ADDING, $byPin['5_2']['status']);
        $this->assertSame(DeviceRoster::REMOVING, $byPin['5_9']['status']);
        $this->assertSame(['total' => 3, 'on_clock' => 1, 'adding' => 1, 'removing' => 1, 'blocked' => 0, 'queued' => 0], $roster['summary']);
    }

    public function test_someone_due_for_removal_still_shows_their_roster_details(): void
    {
        // device_enrollment holds only what the push protocol needs (PIN, name,
        // card), so a row reached through it alone used to render blank.
        $device = $this->device();
        $this->employee('5_9', 109, 'ZULU, Zed', '37:FA:D2:2F');
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_9', 'name' => 'ZULU, Zed']);

        $row = DeviceRoster::forDevice($device)['people']->items()[0];

        $this->assertSame(DeviceRoster::REMOVING, $row['status']);
        $this->assertSame('5', $row['company']);
        $this->assertSame('9', $row['chapa']);
        $this->assertSame(109, (int) $row['payroll_employee_id']);
        $this->assertSame('37:FA:D2:2F', $row['rfid']);
    }

    public function test_an_enrollment_with_no_roster_row_left_falls_back_to_what_the_device_was_told(): void
    {
        // Dropped from the roster entirely, or their PIN became contested — the
        // enrollment is all that is left, and it still has to render.
        $device = $this->device();
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_9', 'name' => 'ZULU, Zed', 'card' => '[37FAD22F]']);

        $row = DeviceRoster::forDevice($device)['people']->items()[0];

        $this->assertSame(DeviceRoster::REMOVING, $row['status']);
        $this->assertSame('ZULU, Zed', $row['name']);
        $this->assertSame('[37FAD22F]', $row['card']);
        $this->assertNull($row['payroll_employee_id']);
    }

    public function test_an_unlinked_clock_has_no_pending_removals(): void
    {
        // The reconciler skips a device with no payroll code, so calling the people
        // on it "waiting to be removed" would invent an intention nothing acts on.
        $device = $this->device(null);
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_9', 'name' => 'ZULU, Zed']);

        $roster = DeviceRoster::forDevice($device);

        $this->assertSame(DeviceRoster::ON_CLOCK, $roster['people']->items()[0]['status']);
        $this->assertSame(0, $roster['summary']['removing']);
    }

    public function test_an_assigned_employee_with_no_record_here_is_blocked_with_a_reason(): void
    {
        $device = $this->device();
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 999]);

        $row = DeviceRoster::forDevice($device)['people']->items()[0];

        $this->assertSame(DeviceRoster::BLOCKED, $row['status']);
        $this->assertSame(999, $row['payroll_employee_id']);
        $this->assertNull($row['pin']);
        $this->assertStringContainsString('No employee record', $row['reason']);
    }

    public function test_a_blocked_employee_caught_on_a_contested_pin_names_the_conflict(): void
    {
        $device = $this->device();
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 999]);
        PinCollision::create([
            'device_pin' => '271_14257',
            'claimants' => [
                ['payroll_employee_id' => 999, 'name' => 'ALPHA, Ann', 'chapa' => '14257', 'company' => '271'],
                ['payroll_employee_id' => 1000, 'name' => 'BRAVO, Ben', 'chapa' => '14257', 'company' => '271'],
            ],
        ]);

        $row = DeviceRoster::forDevice($device)['people']->items()[0];

        $this->assertSame(DeviceRoster::BLOCKED, $row['status']);
        $this->assertSame('271_14257', $row['pin']);
        $this->assertStringContainsString('PIN conflicts', $row['reason']);
    }

    public function test_a_person_with_a_command_still_in_the_queue_is_marked_undelivered(): void
    {
        $device = $this->device();
        $this->employee('5_1', 101, 'ALPHA, Ann');
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 101]);
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_1', 'name' => 'ALPHA, Ann']);
        DeviceCommand::create([
            'device_sn' => 'DEV-1',
            'body' => "DATA UPDATE USERINFO PIN=5_1\tName=ALPHA, Ann\tPri=0\tCard=",
            'status' => 'pending',
        ]);

        $roster = DeviceRoster::forDevice($device);

        $this->assertSame('add', $roster['people']->items()[0]['queued']);
        $this->assertSame(1, $roster['summary']['queued']);
    }

    public function test_breakdown_can_be_searched_and_filtered_by_status(): void
    {
        $device = $this->device();
        $this->employee('5_1', 101, 'ALPHA, Ann');
        $this->employee('5_2', 102, 'BRAVO, Ben');
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 101]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 102]);
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_1', 'name' => 'ALPHA, Ann']);

        $searched = DeviceRoster::forDevice($device, ['search' => 'bravo']);
        $this->assertSame(1, $searched['people']->total());
        $this->assertSame('5_2', $searched['people']->items()[0]['pin']);

        $filtered = DeviceRoster::forDevice($device, ['status' => DeviceRoster::ON_CLOCK]);
        $this->assertSame(1, $filtered['people']->total());
        $this->assertSame('5_1', $filtered['people']->items()[0]['pin']);

        // The summary describes the whole device, not the filtered page.
        $this->assertSame(2, $filtered['summary']['total']);
    }

    public function test_breakdown_carries_each_persons_punch_activity_on_this_clock(): void
    {
        $device = $this->device();
        $this->employee('5_1', 101, 'ALPHA, Ann');
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 101]);
        Attendance::create(['sn' => 'DEV-1', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_1', 'timestamp' => '2026-08-01 08:00:00', 'is_sync' => false]);
        Attendance::create(['sn' => 'DEV-1', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_1', 'timestamp' => '2026-08-02 08:00:00', 'is_sync' => false]);
        // A punch on a different clock must not be counted here.
        Attendance::create(['sn' => 'DEV-2', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_1', 'timestamp' => '2026-08-03 08:00:00', 'is_sync' => false]);

        $row = DeviceRoster::forDevice($device)['people']->items()[0];

        $this->assertSame(2, $row['punch_count']);
        $this->assertStringContainsString('2026-08-02', (string) $row['last_punch_at']);
    }

    public function test_devices_page_shows_a_people_count_per_device(): void
    {
        $this->device();
        $this->employee('5_1', 101, 'ALPHA, Ann');
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 101]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 999]);
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_1', 'name' => 'ALPHA, Ann']);

        $response = $this->get('/devices');

        $response->assertOk();
        $response->assertSee('on the clock');
        $response->assertSee('payroll assigns 2');
        $response->assertSee("1 can't be added", false);
    }

    public function test_device_people_page_lists_the_employees_on_that_device(): void
    {
        $device = $this->device();
        $this->employee('5_1', 101, 'ALPHA, Ann', '1996052557');
        $this->employee('5_2', 102, 'BRAVO, Ben');
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 101]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 102]);
        DeviceEnrollment::create(['device_sn' => 'DEV-1', 'pin' => '5_1', 'name' => 'ALPHA, Ann']);

        $response = $this->get("/devices/{$device->id}/people");

        $response->assertOk();
        $response->assertSee('People on Main Gate');
        $response->assertSee('ALPHA, Ann');
        $response->assertSee('BRAVO, Ben');
        $response->assertSee('1996052557');       // RFID
        $response->assertSee('On the clock');
        $response->assertSee('Waiting to be added');
    }

    public function test_device_people_page_requires_login(): void
    {
        $device = $this->device();

        $this->guest()->get("/devices/{$device->id}/people")->assertRedirect(route('login'));
    }
}
