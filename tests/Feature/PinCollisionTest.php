<?php

namespace Tests\Feature;

use App\Contracts\PayrollClient;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\EmployeeMap;
use App\Models\PinCollision;
use App\Queries\EmployeeDirectory;
use App\Sync\AttendanceSync;
use App\Sync\EnrollmentReconciler;
use App\Sync\RosterSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePayrollClient;
use Tests\TestCase;

/**
 * "{company}_{chapa}" was assumed globally unique; DMPI's live data proves it
 * isn't. A punch carries only the PIN, so a contested PIN cannot be attributed
 * to either claimant — these cover that it is refused rather than guessed, and
 * that an operator's decision sticks.
 */
class PinCollisionTest extends TestCase
{
    use RefreshDatabase;

    /** Two employees both claiming device PIN 5_4968. */
    private function contestedRoster(): array
    {
        return [
            ['id' => 35221, 'company' => '5', 'chapa' => '4968', 'name' => 'FIRST, ONE', 'rfid' => '111'],
            ['id' => 35211, 'company' => '5', 'chapa' => '4968', 'name' => 'SECOND, TWO', 'rfid' => '222'],
        ];
    }

    private function punchOn(string $pin): Attendance
    {
        Device::firstOrCreate(['no_sn' => 'DEV-IN'], ['direction' => 'in']);

        return Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '9999',
            'employee_id' => $pin, 'timestamp' => '2026-06-17 08:01:33',
            'log_type' => 'in', 'is_sync' => false,
        ]);
    }

    public function test_contested_pin_is_parked_and_never_mapped_to_either_claimant(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();

        $result = (new RosterSync($payroll))->sync();

        $this->assertSame(0, EmployeeMap::where('device_pin', '5_4968')->count(), 'a contested PIN must not resolve to anybody');
        $this->assertSame(1, PinCollision::count());
        $this->assertSame(['contested' => 1, 'resolved' => 0], ['contested' => $result['contested'], 'resolved' => $result['resolved']]);

        $collision = PinCollision::first();
        $this->assertSame('5_4968', $collision->device_pin);
        $this->assertEqualsCanonicalizing([35221, 35211], array_column($collision->claimants, 'payroll_employee_id'));
        $this->assertNull($collision->resolved_payroll_employee_id);
    }

    public function test_a_pin_that_becomes_contested_loses_the_mapping_an_earlier_run_guessed(): void
    {
        // What last-write-wins left behind before this fix existed.
        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 35211, 'name' => 'SECOND, TWO',
        ]);

        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();

        (new RosterSync($payroll))->sync();

        $this->assertSame(0, EmployeeMap::where('device_pin', '5_4968')->count(), 'the earlier guess must not survive');
    }

    public function test_punch_on_a_contested_pin_stays_unsynced_and_says_why(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();
        (new RosterSync($payroll))->sync();

        $punch = $this->punchOn('5_4968');
        (new AttendanceSync($payroll))->sync();

        $punch->refresh();
        $this->assertFalse((bool) $punch->is_sync);
        $this->assertEmpty($payroll->pushed, 'a contested punch must never reach payroll');
        $this->assertStringContainsString('claimed by 2 payroll employees', $punch->sync_error);
        $this->assertStringContainsString('35221', $punch->sync_error);
        $this->assertStringContainsString('35211', $punch->sync_error);
    }

    public function test_unmapped_pin_still_reports_the_plain_reason(): void
    {
        $punch = $this->punchOn('9_9999');

        (new AttendanceSync(new FakePayrollClient))->sync();

        $this->assertSame('No employee mapping for device PIN 9_9999.', $punch->fresh()->sync_error);
    }

    public function test_contested_pin_is_not_enrolled_onto_a_device(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();
        (new RosterSync($payroll))->sync();

        Device::create(['no_sn' => 'DEV-1', 'direction' => 'in', 'payroll_device_code' => 'C1']);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 35211]);

        (new EnrollmentReconciler)->reconcileDevice('DEV-1');

        $this->assertSame(0, DeviceCommand::count(), 'an ambiguous PIN must not be pushed to a device under a guessed name');
    }

    public function test_resolving_maps_the_pin_and_releases_its_waiting_punches(): void
    {
        $this->app->instance(PayrollClient::class, $payroll = new FakePayrollClient);
        $payroll->employees = $this->contestedRoster();
        (new RosterSync($payroll))->sync();

        $punch = $this->punchOn('5_4968');
        $collision = PinCollision::first();

        $this->post(route('employees.conflicts.resolve', $collision), ['payroll_employee_id' => 35211])
            ->assertRedirect();

        $map = EmployeeMap::where('device_pin', '5_4968')->first();
        $this->assertNotNull($map);
        $this->assertSame(35211, (int) $map->payroll_employee_id);
        $this->assertSame('SECOND, TWO', $map->name);
        $this->assertSame('222', $map->rfid, 'the chosen claimant\'s own card must be used');

        (new AttendanceSync($payroll))->sync();
        $this->assertTrue((bool) $punch->fresh()->is_sync);
        $this->assertSame(35211, $payroll->pushed[0]->employee);
    }

    public function test_a_decision_survives_the_next_roster_pull(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();
        (new RosterSync($payroll))->sync();

        PinCollision::first()->forceFill([
            'resolved_payroll_employee_id' => 35211, 'resolved_at' => now(),
        ])->save();

        $result = (new RosterSync($payroll))->sync();

        $this->assertSame(1, $result['resolved']);
        $this->assertSame(35211, (int) EmployeeMap::where('device_pin', '5_4968')->value('payroll_employee_id'));
        $this->assertSame(35211, (int) PinCollision::first()->resolved_payroll_employee_id);
    }

    public function test_a_decision_is_dropped_when_the_chosen_employee_stops_claiming_the_pin(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();
        (new RosterSync($payroll))->sync();

        PinCollision::first()->forceFill([
            'resolved_payroll_employee_id' => 35211, 'resolved_at' => now(),
        ])->save();

        // DMPI replaces 35211 with a different employee — the standing decision is
        // no longer a decision about this conflict.
        $payroll->employees = [
            ['id' => 35221, 'company' => '5', 'chapa' => '4968', 'name' => 'FIRST, ONE'],
            ['id' => 99999, 'company' => '5', 'chapa' => '4968', 'name' => 'THIRD, NEW'],
        ];

        $result = (new RosterSync($payroll))->sync();

        $this->assertSame(0, $result['resolved']);
        $this->assertNull(PinCollision::first()->resolved_payroll_employee_id);
        $this->assertSame(0, EmployeeMap::where('device_pin', '5_4968')->count());
    }

    public function test_collision_clears_and_the_pin_maps_normally_once_dmpi_drops_the_duplicate(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();
        (new RosterSync($payroll))->sync();
        $this->assertSame(1, PinCollision::count());

        $payroll->employees = [['id' => 35211, 'company' => '5', 'chapa' => '4968', 'name' => 'SECOND, TWO']];
        (new RosterSync($payroll))->sync();

        $this->assertSame(0, PinCollision::count());
        $this->assertSame(35211, (int) EmployeeMap::where('device_pin', '5_4968')->value('payroll_employee_id'));
    }

    public function test_withdrawing_a_decision_unmaps_the_pin_again(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();
        (new RosterSync($payroll))->sync();
        $collision = PinCollision::first();

        $this->post(route('employees.conflicts.resolve', $collision), ['payroll_employee_id' => 35211]);
        $this->assertSame(1, EmployeeMap::where('device_pin', '5_4968')->count());

        $this->post(route('employees.conflicts.clear', $collision))->assertRedirect();

        $this->assertSame(0, EmployeeMap::where('device_pin', '5_4968')->count());
        $this->assertNull(PinCollision::first()->resolved_payroll_employee_id);
    }

    public function test_cannot_assign_a_pin_to_an_employee_who_does_not_claim_it(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();
        (new RosterSync($payroll))->sync();

        $this->post(route('employees.conflicts.resolve', PinCollision::first()), ['payroll_employee_id' => 12345])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, EmployeeMap::where('device_pin', '5_4968')->count());
        $this->assertNull(PinCollision::first()->resolved_payroll_employee_id);
    }

    public function test_contested_pins_are_listed_as_conflicts_not_as_unmapped(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();
        (new RosterSync($payroll))->sync();
        $this->punchOn('5_4968');
        $this->punchOn('9_9999');

        $unmapped = EmployeeDirectory::unmappedPins();
        $this->assertSame(['9_9999'], $unmapped->pluck('employee_id')->all());

        $conflicts = EmployeeDirectory::collisions();
        $this->assertSame(['5_4968'], $conflicts->pluck('device_pin')->all());
        $this->assertSame(1, $conflicts->first()->stuck_punches);
    }

    public function test_conflicts_tab_renders_the_claimants_and_the_stuck_count(): void
    {
        $payroll = new FakePayrollClient;
        $payroll->employees = $this->contestedRoster();
        (new RosterSync($payroll))->sync();
        $this->punchOn('5_4968');

        $this->get(route('employees.index', ['tab' => 'conflicts']))
            ->assertOk()
            ->assertSee('PIN conflicts')
            ->assertSee('5_4968')
            ->assertSee('FIRST, ONE')
            ->assertSee('SECOND, TWO')
            ->assertSee('Undecided')
            ->assertSee('1 punch(es) waiting');
    }
}
