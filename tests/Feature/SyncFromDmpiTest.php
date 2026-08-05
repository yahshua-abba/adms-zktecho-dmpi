<?php

namespace Tests\Feature;

use App\Contracts\PayrollClient;
use App\Models\DeviceAssignment;
use App\Models\EmployeeMap;
use App\Models\PayrollDevice;
use App\Models\SyncRun;
use App\Sync\DmpiSyncLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakePayrollClient;
use Tests\Support\FakeSyncLauncher;
use Tests\TestCase;

class SyncFromDmpiTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider parts */
    public function test_each_button_starts_its_own_background_run_and_returns_immediately(string $part): void
    {
        $this->app->instance(DmpiSyncLauncher::class, $launcher = new FakeSyncLauncher);
        $this->app->instance(PayrollClient::class, $payroll = new FakePayrollClient);

        $this->post(route('dmpi.sync', $part))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame([$part], $launcher->parts);
        // The web request itself must not talk to payroll — that's the whole point.
        $this->assertSame(0, EmployeeMap::count());
        $this->assertEmpty($payroll->pushed);
        $this->assertDatabaseHas('activity_log', ['event' => 'dmpi.pull']);
    }

    public static function parts(): array
    {
        return [['employees'], ['devices'], ['assignments']];
    }

    public function test_an_unknown_part_is_not_a_route(): void
    {
        $this->app->instance(DmpiSyncLauncher::class, $launcher = new FakeSyncLauncher);

        $this->post('/dmpi/sync/everything')->assertNotFound();

        $this->assertSame(0, $launcher->started);
    }

    public function test_the_three_buttons_share_one_lock(): void
    {
        $this->app->instance(DmpiSyncLauncher::class, $launcher = new FakeSyncLauncher);

        // Stand in for a run already in flight — BOTH halves of it. A held lock with
        // no live run is an orphan (a crashed process cannot hand its lock back) and
        // is deliberately reclaimed, so the run record has to exist for this to be a
        // genuinely-running download rather than wreckage.
        $held = Cache::lock(DmpiSyncLauncher::LOCK, 60);
        $this->assertTrue($held->get());
        SyncRun::create([
            'part' => 'employees', 'status' => 'running', 'pid' => getmypid(), 'started_at' => now(),
        ]);

        foreach (['employees', 'devices', 'assignments'] as $part) {
            $this->post(route('dmpi.sync', $part))
                ->assertRedirect()
                ->assertSessionHas('error');
        }

        $this->assertSame(0, $launcher->started, 'nothing may pile on top of a running download');

        $held->release();
    }

    /**
     * The controller's pre-flight check is NOT the guard — it only reports. The
     * launched command exits before anything re-reads the lock, so two presses a
     * second apart both saw it free and two full device pulls ran at once, each
     * with its own connection to DMPI's production server. Every download command
     * must hold the lock for its own run.
     *
     * @dataProvider downloadCommands
     */
    public function test_a_download_command_refuses_to_run_while_another_holds_the_lock(string $command): void
    {
        $fake = new FakePayrollClient;
        $fake->employees = [['id' => 1, 'company' => '5', 'chapa' => '1', 'name' => 'X']];
        $fake->deviceInfo = [
            'devices' => [['code' => 'C1', 'name' => 'Gate 1']],
            'assignments' => [['employee_id' => 1, 'device_code' => 'C1']],
        ];
        $this->app->instance(PayrollClient::class, $fake);

        $held = Cache::lock(DmpiSyncLauncher::LOCK, 60);
        $this->assertTrue($held->get());

        $this->artisan($command)->assertSuccessful();

        $this->assertSame(0, EmployeeMap::count(), "{$command} ran on top of a held lock");
        $this->assertSame(0, PayrollDevice::count(), "{$command} ran on top of a held lock");

        $held->release();
    }

    public static function downloadCommands(): array
    {
        return [
            ['payroll:sync-roster'],
            ['payroll:sync-devices'],
            ['payroll:sync-devices --only=devices'],
            ['payroll:sync-devices --only=assignments'],
            ['payroll:sync-all'],
        ];
    }

    /** @dataProvider downloadCommands */
    public function test_a_download_command_releases_the_lock_for_the_next_one(string $command): void
    {
        $fake = new FakePayrollClient;
        $fake->employees = [['id' => 1, 'company' => '5', 'chapa' => '1', 'name' => 'X']];
        $fake->deviceInfo = [
            'devices' => [['code' => 'C1', 'name' => 'Gate 1']],
            'assignments' => [['employee_id' => 1, 'device_code' => 'C1']],
        ];
        $this->app->instance(PayrollClient::class, $fake);

        $this->artisan($command)->assertSuccessful();

        $lock = Cache::lock(DmpiSyncLauncher::LOCK, 5);
        $this->assertTrue($lock->get(), "{$command} left the lock held");
        $lock->release();
    }

    public function test_sync_all_command_runs_every_stage(): void
    {
        $fake = new FakePayrollClient;
        $fake->employees = [['id' => 35042, 'company' => '267', 'chapa' => '123123', 'name' => 'BAYRON, RON MICHAEL', 'rfid' => '1996052557']];
        $fake->deviceInfo = [
            'devices' => [['code' => 'C1', 'name' => 'Gate 1']],
            'assignments' => [['employee_id' => 35042, 'device_code' => 'C1']],
        ];
        $this->app->instance(PayrollClient::class, $fake);

        $this->artisan('payroll:sync-all')->assertSuccessful();

        $map = EmployeeMap::where('payroll_employee_id', 35042)->first();
        $this->assertNotNull($map);
        $this->assertSame('267_123123', $map->device_pin);
        $this->assertSame('1996052557', $map->rfid);
        $this->assertSame(1, DeviceAssignment::where('device_code', 'C1')->count());
    }

    public function test_sync_all_command_skips_when_another_run_holds_the_lock(): void
    {
        $fake = new FakePayrollClient;
        $fake->employees = [['id' => 1, 'company' => '5', 'chapa' => '1', 'name' => 'X']];
        $this->app->instance(PayrollClient::class, $fake);

        $held = Cache::lock(DmpiSyncLauncher::LOCK, 60);
        $this->assertTrue($held->get());

        $this->artisan('payroll:sync-all')->assertSuccessful();

        $this->assertSame(0, EmployeeMap::count(), 'the overlapping run must not have repeated the work');

        $held->release();
    }

    public function test_sync_all_command_releases_the_lock_after_a_failure(): void
    {
        $fake = new FakePayrollClient;
        $fake->employees = []; // an empty roster is refused, so this run fails
        $this->app->instance(PayrollClient::class, $fake);

        $this->artisan('payroll:sync-all')->assertFailed();

        // A failed run must not wedge every later sync.
        $lock = Cache::lock(DmpiSyncLauncher::LOCK, 5);
        $this->assertTrue($lock->get(), 'the lock should have been released');
        $lock->release();
    }

    // The roster download belongs to Employees; the two device-side downloads
    // belong to Devices. Asserting the absences too, so a stray copy of a button
    // on the wrong page is a failing test rather than a silent duplicate.
    public function test_employees_page_shows_only_the_roster_download(): void
    {
        $this->get(route('employees.index'))
            ->assertOk()
            ->assertSee('Download employees')
            ->assertDontSee('Download devices')
            ->assertDontSee('Download assignments');
    }

    public function test_devices_page_shows_the_device_and_assignment_downloads(): void
    {
        $this->get(route('devices.index'))
            ->assertOk()
            ->assertSee('Download devices')
            ->assertSee('Download assignments')
            ->assertDontSee('Download employees');
    }

    // The warning is the whole point of the button, so the wiring that delivers it
    // is worth pinning: an unconfirmed submit path, or a submit button pointing at
    // no form, would both leave the machines rewritable in one unguarded click.
    public function test_downloading_assignments_is_confirmed_through_the_modal(): void
    {
        $html = $this->get(route('devices.index'))->assertOk()->getContent();

        // The button opens the modal; it never submits on its own.
        $this->assertStringContainsString('data-bs-target="#confirm-assignments"', $html);
        $this->assertMatchesRegularExpression(
            '/<form id="download-assignments" action="[^"]*dmpi\/sync\/assignments"/',
            $html
        );
        // ...and the only thing that submits that form is the modal's button.
        $this->assertStringContainsString('form="download-assignments" class="btn btn-danger"', $html);
        $this->assertSame(1, substr_count($html, 'form="download-assignments"'));

        // The parts of the warning that carry the actual risk.
        $this->assertStringContainsString('physical machine on the wall', $html);
        $this->assertStringContainsString('erases their fingerprint', $html);
    }

    public function test_devices_only_refreshes_the_clock_list_and_leaves_assignments_alone(): void
    {
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 111]);

        $fake = new FakePayrollClient;
        $fake->deviceInfo = [
            'devices' => [['code' => 'C1', 'name' => 'Gate 1']],
            'assignments' => [['employee_id' => 48213, 'device_code' => 'C1']],
        ];
        $this->app->instance(PayrollClient::class, $fake);

        $this->artisan('payroll:sync-devices --only=devices')->assertSuccessful();

        $this->assertDatabaseHas('payroll_devices', ['code' => 'C1', 'name' => 'Gate 1']);
        // The existing assignment must survive untouched — that's the whole point of
        // being able to refresh the clock list on its own.
        $this->assertSame(1, DeviceAssignment::count());
        $this->assertDatabaseHas('device_assignments', ['payroll_employee_id' => 111]);
    }

    public function test_assignments_only_replaces_assignments(): void
    {
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 111]); // stale

        $fake = new FakePayrollClient;
        $fake->deviceInfo = [
            'devices' => [['code' => 'C1', 'name' => 'Gate 1']],
            'assignments' => [['employee_id' => 48213, 'device_code' => 'C1']],
        ];
        $this->app->instance(PayrollClient::class, $fake);

        $this->artisan('payroll:sync-devices --only=assignments')->assertSuccessful();

        $this->assertDatabaseMissing('device_assignments', ['payroll_employee_id' => 111]);
        $this->assertDatabaseHas('device_assignments', ['payroll_employee_id' => 48213]);
    }

    public function test_an_invalid_only_option_is_rejected_rather_than_treated_as_everything(): void
    {
        $fake = new FakePayrollClient;
        $fake->deviceInfo = ['devices' => [['code' => 'C1']], 'assignments' => []];
        $this->app->instance(PayrollClient::class, $fake);

        $this->artisan('payroll:sync-devices --only=nonsense')->assertFailed();

        $this->assertSame(0, PayrollDevice::count(), 'a typo must not silently run the full sync');
    }
}
