<?php

namespace Tests\Feature;

use App\Models\DeviceCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceCommandDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_getrequest_returns_pending_commands_and_marks_them_sent(): void
    {
        $cmd = DeviceCommand::create(['device_sn' => 'DEV1', 'body' => 'DATA UPDATE USERINFO PIN=5_4968', 'status' => 'pending']);

        $response = $this->get('/iclock/getrequest?SN=DEV1');

        $response->assertOk();
        $response->assertSee("C:{$cmd->id}:DATA UPDATE USERINFO PIN=5_4968", false);
        $this->assertSame('sent', $cmd->fresh()->status);
    }

    public function test_getrequest_only_returns_commands_for_that_device(): void
    {
        DeviceCommand::create(['device_sn' => 'OTHER', 'body' => 'X', 'status' => 'pending']);

        $this->get('/iclock/getrequest?SN=DEV1')->assertOk()->assertSee('OK');
        $this->assertSame('pending', DeviceCommand::where('device_sn', 'OTHER')->first()->status);
    }

    public function test_getrequest_hands_over_only_the_configured_batch(): void
    {
        config(['adms.device_command_batch_size' => 2]);

        $first = DeviceCommand::create(['device_sn' => 'DEV1', 'body' => 'FIRST', 'status' => 'pending']);
        $second = DeviceCommand::create(['device_sn' => 'DEV1', 'body' => 'SECOND', 'status' => 'pending']);
        $third = DeviceCommand::create(['device_sn' => 'DEV1', 'body' => 'THIRD', 'status' => 'pending']);

        $response = $this->get('/iclock/getrequest?SN=DEV1');

        $response->assertOk();
        $this->assertSame("C:{$first->id}:FIRST\nC:{$second->id}:SECOND\n", $response->getContent());
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('sent', $first->fresh()->status);
        $this->assertSame('sent', $second->fresh()->status);
        $this->assertSame('pending', $third->fresh()->status);
    }

    public function test_devicecmd_marks_command_done_on_success(): void
    {
        $cmd = DeviceCommand::create(['device_sn' => 'DEV1', 'body' => 'X', 'status' => 'sent']);

        $reply = "ID={$cmd->id}&Return=0&CMD=DATA";

        $this->call('POST', '/iclock/devicecmd?SN=DEV1', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $reply)
            ->assertOk();

        $this->assertSame('done', $cmd->fresh()->status);
        $this->assertSame(0, $cmd->fresh()->return_code);
        $this->assertSame($reply, $cmd->fresh()->response);
        $this->assertNotNull($cmd->fresh()->done_at);
    }

    public function test_devicecmd_marks_command_failed_on_nonzero_return(): void
    {
        $cmd = DeviceCommand::create(['device_sn' => 'DEV1', 'body' => 'X', 'status' => 'sent']);

        $this->call('POST', '/iclock/devicecmd?SN=DEV1', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "ID={$cmd->id}&Return=-1002&CMD=DATA")
            ->assertOk();

        $this->assertSame('failed', $cmd->fresh()->status);
        $this->assertSame(-1002, $cmd->fresh()->return_code);
        $this->assertSame("ID={$cmd->id}&Return=-1002&CMD=DATA", $cmd->fresh()->response);
    }

    public function test_a_device_cannot_report_a_result_for_another_devices_command(): void
    {
        $cmd = DeviceCommand::create(['device_sn' => 'DEV2', 'body' => 'X', 'status' => 'sent']);

        $this->call('POST', '/iclock/devicecmd?SN=DEV1', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "ID={$cmd->id}&Return=0&CMD=DATA")
            ->assertOk();

        $this->assertSame('sent', $cmd->fresh()->status);
        $this->assertNull($cmd->fresh()->response);
    }
}
