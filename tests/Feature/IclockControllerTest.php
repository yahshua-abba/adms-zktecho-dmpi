<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
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

        $device = Device::where('no_sn', 'DEV1')->first();
        $this->assertNotNull($device);
        $this->assertTrue($device->isOnline(), 'A punch push should keep the device online');
    }

    public function test_command_poll_marks_the_device_online(): void
    {
        $this->get('/iclock/getrequest?SN=DEV1')->assertOk();

        $this->assertTrue(Device::where('no_sn', 'DEV1')->first()->isOnline());
    }

    public function test_punch_freezes_log_type_from_device_direction_at_arrival(): void
    {
        Device::create(['no_sn' => 'DEV1', 'direction' => 'out']);

        $this->call('POST', '/iclock/cdata?SN=DEV1&table=ATTLOG&Stamp=9999', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "5_4968\t2026-06-17 08:00:00\t0\t1\t\t0\t0\n")
            ->assertOk();

        $this->assertSame('out', DB::table('attendances')->where('sn', 'DEV1')->value('log_type'));
    }

    public function test_changing_device_direction_does_not_rewrite_an_existing_punch(): void
    {
        Device::create(['no_sn' => 'DEV1', 'direction' => 'in']);
        $this->call('POST', '/iclock/cdata?SN=DEV1&table=ATTLOG&Stamp=9999', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "5_4968\t2026-06-17 08:00:00\t0\t1\t\t0\t0\n")
            ->assertOk();

        Device::where('no_sn', 'DEV1')->update(['direction' => 'out']);

        $this->assertSame('in', DB::table('attendances')->where('sn', 'DEV1')->value('log_type'));
    }

    public function test_both_direction_device_freezes_in_out_from_punch_state(): void
    {
        Device::create(['no_sn' => 'DEV1', 'direction' => 'both']);

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

    public function test_user_upload_confirms_a_queried_pin_is_present(): void
    {
        $source = DeviceCommand::create([
            'device_sn' => 'DEV1',
            'body' => 'DATA UPDATE USERINFO PIN=5_4968',
            'status' => 'sent',
        ]);
        $query = DeviceCommand::create([
            'device_sn' => 'DEV1',
            'body' => 'DATA QUERY USERINFO PIN=5_4968',
            'status' => 'done',
            'return_code' => 0,
            'source_command_id' => $source->id,
        ]);
        $payload = "USER PIN=5_4968\tName=Rubelyn\tPri=0\tCard=[552DE3D3]";

        $this->call('POST', '/iclock/cdata?SN=DEV1&table=OPERLOG&Stamp=9999', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $payload)
            ->assertOk()
            ->assertSee('OK: 1');

        $this->assertSame('present', $query->fresh()->verification_status);
        $this->assertSame($payload, $query->fresh()->verification_payload);
        $this->assertNotNull($query->fresh()->verified_at);
        $this->assertSame('done', $source->fresh()->status);
        $this->assertSame('present', $source->fresh()->verification_status);
        $this->assertSame($payload, $source->fresh()->verification_payload);
        $this->assertSame(0, DB::table('attendances')->count());
    }

    public function test_user_upload_does_not_complete_a_query_for_another_pin(): void
    {
        $query = DeviceCommand::create([
            'device_sn' => 'DEV1',
            'body' => 'DATA QUERY USERINFO PIN=5_4968',
            'status' => 'done',
        ]);

        $this->call('POST', '/iclock/cdata?SN=DEV1&table=OPERLOG&Stamp=9999', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "USER PIN=5_5000\tName=Other")
            ->assertOk();

        $this->assertNull($query->fresh()->verification_status);
    }
}
