<?php

namespace Tests\Feature;

use App\Maintenance\LogPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogPrunerTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_raw_logs_older_than_retention_window(): void
    {
        DB::table('device_log')->insert(['data' => 'old', 'url' => '{}', 'sn' => 'A', 'created_at' => now()->subDays(40)]);
        DB::table('device_log')->insert(['data' => 'recent', 'url' => '{}', 'sn' => 'A', 'created_at' => now()->subDays(5)]);
        DB::table('finger_log')->insert(['data' => 'old', 'url' => '{}', 'created_at' => now()->subDays(40)]);
        DB::table('finger_log')->insert(['data' => 'recent', 'url' => '{}', 'created_at' => now()->subDays(5)]);

        $deleted = LogPruner::prune(30);

        $this->assertSame(1, $deleted['device_log']);
        $this->assertSame(1, $deleted['finger_log']);
        $this->assertSame(1, DB::table('device_log')->count());
        $this->assertSame(1, DB::table('finger_log')->count());
        $this->assertSame('recent', DB::table('device_log')->value('data'));
    }

    public function test_command_prunes_with_default_retention(): void
    {
        DB::table('device_log')->insert(['data' => 'old', 'url' => '{}', 'sn' => 'A', 'created_at' => now()->subDays(400)]);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertSame(0, DB::table('device_log')->count());
    }

    private function activity(string $level, int $daysAgo, string $message = 'x'): void
    {
        DB::table('activity_log')->insert([
            'level' => $level,
            'event' => 'attendance.sync',
            'message' => $message,
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * Volume and value point in opposite directions in this table: nearly every
     * row is the every-minute push reporting nothing happened, while the handful
     * of warnings and errors are the entire reason anyone opens the page.
     */
    public function test_routine_activity_is_aged_out_but_warnings_and_errors_are_kept(): void
    {
        $this->activity('info', 40, 'routine');
        $this->activity('info', 5, 'recent');
        $this->activity('warning', 40, 'a warning');
        $this->activity('error', 40, 'an error');

        $deleted = LogPruner::prune(30, 365);

        $this->assertSame(1, $deleted['activity_log']);
        $this->assertSame(
            ['a warning', 'an error', 'recent'],
            DB::table('activity_log')->orderBy('message')->pluck('message')->all()
        );
    }

    public function test_warnings_and_errors_are_aged_out_eventually(): void
    {
        $this->activity('error', 400, 'ancient');
        $this->activity('error', 100, 'old but interesting');

        LogPruner::prune(30, 365);

        $this->assertSame(['old but interesting'], DB::table('activity_log')->pluck('message')->all());
    }

    /**
     * Deleting the interesting rows before the routine ones around them is never
     * what anyone means by a shorter setting, so the shorter of the two loses.
     */
    public function test_an_error_window_shorter_than_the_routine_one_is_ignored(): void
    {
        $this->activity('error', 100, 'an error');

        LogPruner::prune(365, 30);

        $this->assertSame(1, DB::table('activity_log')->count());
    }

    private function command(string $status, int $daysAgo): void
    {
        DB::table('device_commands')->insert([
            'device_sn' => 'A', 'body' => 'DATA UPDATE USERINFO', 'status' => $status,
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * The devices this queue waits on are exactly the ones that go offline for
     * weeks. Pruning by age alone would quietly cancel the enrollment changes for
     * every reader that had been unplugged the longest — the one case where the
     * queue matters most.
     */
    public function test_finished_device_commands_are_pruned_but_undelivered_ones_are_not(): void
    {
        $this->command('done', 40);
        $this->command('failed', 40);
        $this->command('pending', 40);
        $this->command('sent', 40);
        $this->command('done', 5);

        $deleted = LogPruner::prune(30, 365);

        $this->assertSame(2, $deleted['device_commands']);
        $this->assertSame(
            ['done', 'pending', 'sent'],
            DB::table('device_commands')->orderBy('status')->pluck('status')->all()
        );
    }

    public function test_rejected_device_payloads_are_kept_on_the_long_window(): void
    {
        DB::table('error_log')->insert(['data' => 'ancient', 'created_at' => now()->subDays(400)]);
        DB::table('error_log')->insert(['data' => 'old', 'created_at' => now()->subDays(100)]);

        LogPruner::prune(30, 365);

        $this->assertSame(['old'], DB::table('error_log')->pluck('data')->all());
    }

    /**
     * `--days=0` means "clear it out now". Read through PHP's `?:` that was
     * indistinguishable from not passing the option, so the command reported a
     * successful prune while quietly using the 30-day default instead.
     */
    public function test_a_zero_day_window_is_obeyed_rather_than_treated_as_unset(): void
    {
        DB::table('device_log')->insert(['data' => 'today', 'url' => '{}', 'sn' => 'A', 'created_at' => now()->subMinute()]);

        $this->artisan('logs:prune --days=0 --error-days=0')->assertSuccessful();

        $this->assertSame(0, DB::table('device_log')->count());
    }

    /** A nightly "deleted nothing" note would be a row next month's run has to delete. */
    public function test_a_prune_that_deleted_nothing_does_not_write_an_activity_row(): void
    {
        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertSame(0, DB::table('activity_log')->count());
    }

    public function test_a_prune_that_deleted_something_says_so_in_the_activity_log(): void
    {
        DB::table('device_log')->insert(['data' => 'old', 'url' => '{}', 'sn' => 'A', 'created_at' => now()->subDays(400)]);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['event' => 'logs.prune']);
    }
}
