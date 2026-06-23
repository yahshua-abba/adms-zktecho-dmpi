<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IclockControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_heartbeat_updates_online_but_is_not_logged(): void
    {
        $this->get('/iclock/cdata?SN=DEV1')->assertOk();

        $this->assertDatabaseHas('devices', ['no_sn' => 'DEV1']);
        $this->assertNotNull(DB::table('devices')->where('no_sn', 'DEV1')->value('online'));
        $this->assertSame(0, DB::table('device_log')->count());
    }

    public function test_option_request_is_logged(): void
    {
        $this->get('/iclock/cdata?SN=DEV1&options=all')->assertOk();

        $this->assertSame(1, DB::table('device_log')->count());
    }

    public function test_duplicate_punch_resend_is_ignored_on_ingest(): void
    {
        $uri = '/iclock/cdata?SN=DEV1&table=ATTLOG&Stamp=9999';
        $body = "5_4968\t2026-06-17 08:00:00\t0\t1\t\t0\t0\n";

        $post = fn () => $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);

        $post()->assertOk();
        $post()->assertOk(); // device re-sends the same record

        $this->assertSame(1, DB::table('attendances')->where('sn', 'DEV1')->count());
    }

    public function test_punch_push_marks_the_device_online(): void
    {
        $this->call('POST', '/iclock/cdata?SN=DEV1&table=ATTLOG&Stamp=9999', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "5_4968\t2026-06-17 08:00:00\t0\t1\t\t0\t0\n")
            ->assertOk();

        $device = \App\Models\Device::where('no_sn', 'DEV1')->first();
        $this->assertNotNull($device);
        $this->assertTrue($device->isOnline(), 'A punch push should keep the device online');
    }

    public function test_command_poll_marks_the_device_online(): void
    {
        $this->get('/iclock/getrequest?SN=DEV1')->assertOk();

        $this->assertTrue(\App\Models\Device::where('no_sn', 'DEV1')->first()->isOnline());
    }

    public function test_punch_freezes_log_type_from_device_direction_at_arrival(): void
    {
        \App\Models\Device::create(['no_sn' => 'DEV1', 'direction' => 'out']);

        $this->call('POST', '/iclock/cdata?SN=DEV1&table=ATTLOG&Stamp=9999', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "5_4968\t2026-06-17 08:00:00\t0\t1\t\t0\t0\n")
            ->assertOk();

        $this->assertSame('out', DB::table('attendances')->where('sn', 'DEV1')->value('log_type'));
    }

    public function test_changing_device_direction_does_not_rewrite_an_existing_punch(): void
    {
        \App\Models\Device::create(['no_sn' => 'DEV1', 'direction' => 'in']);
        $this->call('POST', '/iclock/cdata?SN=DEV1&table=ATTLOG&Stamp=9999', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "5_4968\t2026-06-17 08:00:00\t0\t1\t\t0\t0\n")
            ->assertOk();

        \App\Models\Device::where('no_sn', 'DEV1')->update(['direction' => 'out']);

        $this->assertSame('in', DB::table('attendances')->where('sn', 'DEV1')->value('log_type'));
    }

    public function test_both_direction_device_freezes_in_out_from_punch_state(): void
    {
        \App\Models\Device::create(['no_sn' => 'DEV1', 'direction' => 'both']);

        $this->call('POST', '/iclock/cdata?SN=DEV1&table=ATTLOG&Stamp=9999', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "5_4968\t2026-06-17 08:00:00\t1\t1\t\t0\t0\n")
            ->assertOk();

        $this->assertSame('out', DB::table('attendances')->where('sn', 'DEV1')->value('log_type'));
    }

    public function test_distinct_punches_are_both_stored(): void
    {
        $uri = '/iclock/cdata?SN=DEV1&table=ATTLOG&Stamp=9999';

        $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], "5_4968\t2026-06-17 08:00:00\t0\t1\t\t0\t0\n")->assertOk();
        $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], "5_4968\t2026-06-17 12:00:00\t0\t1\t\t0\t0\n")->assertOk();

        $this->assertSame(2, DB::table('attendances')->where('sn', 'DEV1')->count());
    }
}
