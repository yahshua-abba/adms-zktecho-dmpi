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
}
