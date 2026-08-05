<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\EmployeeMap;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clearing the database is the one action here that destroys data nothing can
 * bring back — unsynced punches exist only on this box. Most of these tests are
 * about it REFUSING: a wrong password, a missing confirmation, a download in
 * flight. The one that wipes is almost the least interesting.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function seedSomething(): void
    {
        Device::create(['no_sn' => 'DEV-IN', 'direction' => 'in']);
        EmployeeMap::create(['device_pin' => '5_1', 'company' => '5', 'chapa' => '1', 'payroll_employee_id' => 1]);
        Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_1',
            'timestamp' => '2026-06-17 08:00:00', 'log_type' => 'in', 'is_sync' => false,
        ]);
    }

    private function password(): string
    {
        return (string) config('adms.auth.password');
    }

    public function test_settings_page_is_behind_the_login(): void
    {
        $this->guest()->get(route('settings'))->assertRedirect(route('login'));
    }

    public function test_settings_page_shows_what_would_be_lost(): void
    {
        $this->seedSomething();

        $this->get(route('settings'))
            ->assertOk()
            ->assertSee('Danger zone')
            ->assertSee('Clear the database')
            ->assertSee('never sent to payroll')
            ->assertSee('IN/OUT direction');
    }

    public function test_a_wrong_password_changes_nothing(): void
    {
        $this->seedSomething();

        $this->post(route('settings.clear-database'), [
            'password' => 'not-the-password',
            'confirmation' => 'confirm',
        ])->assertRedirect()->assertSessionHasErrors('password');

        $this->assertSame(1, Attendance::count(), 'nothing may be destroyed on a bad password');
        $this->assertSame(1, EmployeeMap::count());
    }

    public function test_the_word_confirm_is_required(): void
    {
        $this->seedSomething();

        $this->post(route('settings.clear-database'), [
            'password' => $this->password(),
            'confirmation' => 'yes',
        ])->assertRedirect()->assertSessionHasErrors('confirmation');

        $this->assertSame(1, Attendance::count());
    }

    public function test_confirmation_is_forgiving_about_case_and_spacing(): void
    {
        $this->seedSomething();

        $this->post(route('settings.clear-database'), [
            'password' => $this->password(),
            'confirmation' => '  CONFIRM ',
        ])->assertRedirect(route('settings'));

        $this->assertSame(0, Attendance::count());
    }

    public function test_clearing_empties_everything_and_leaves_the_tables_usable(): void
    {
        $this->seedSomething();

        $this->post(route('settings.clear-database'), [
            'password' => $this->password(),
            'confirmation' => 'confirm',
        ])->assertRedirect(route('settings'))->assertSessionHas('success');

        $this->assertSame(0, Attendance::count());
        $this->assertSame(0, EmployeeMap::count());
        $this->assertSame(0, Device::count());

        // Rebuilt, not merely emptied — the app has to keep working afterwards.
        Device::create(['no_sn' => 'NEW', 'direction' => 'out']);
        $this->assertSame(1, Device::count());
    }

    public function test_the_wipe_is_recorded_after_the_rebuild_with_what_was_lost(): void
    {
        $this->seedSomething();

        $this->post(route('settings.clear-database'), [
            'password' => $this->password(),
            'confirmation' => 'confirm',
        ])->assertRedirect();

        // activity_log is dropped too, so this entry can only exist if it was
        // written after the rebuild — otherwise there'd be no trace at all.
        $entry = ActivityLog::where('event', 'database.cleared')->first();
        $this->assertNotNull($entry, 'a wipe must leave a record of itself');
        $this->assertSame('error', $entry->level);
        $this->assertStringContainsString('1 never synced', $entry->message);
    }

    public function test_clearing_is_blocked_while_a_download_is_running(): void
    {
        $this->seedSomething();
        SyncRun::create([
            'part' => 'employees', 'status' => 'running', 'pid' => getmypid(), 'started_at' => now(),
        ]);

        $this->post(route('settings.clear-database'), [
            'password' => $this->password(),
            'confirmation' => 'confirm',
        ])->assertRedirect()->assertSessionHasErrors('confirmation');

        $this->assertSame(1, Attendance::count(), 'a running download must not have the tables pulled from under it');
    }

    public function test_the_settings_link_is_in_the_sidebar(): void
    {
        $this->get(route('help'))->assertOk()->assertSee(route('settings'));
    }
}
