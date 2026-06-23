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

    public function test_devicecmd_marks_command_done_on_success(): void
    {
        $cmd = DeviceCommand::create(['device_sn' => 'DEV1', 'body' => 'X', 'status' => 'sent']);

        $this->call('POST', '/iclock/devicecmd?SN=DEV1', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "ID={$cmd->id}&Return=0&CMD=DATA")
            ->assertOk();

        $this->assertSame('done', $cmd->fresh()->status);
        $this->assertSame(0, $cmd->fresh()->return_code);
    }

    public function test_devicecmd_marks_command_failed_on_nonzero_return(): void
    {
        $cmd = DeviceCommand::create(['device_sn' => 'DEV1', 'body' => 'X', 'status' => 'sent']);

        $this->call('POST', '/iclock/devicecmd?SN=DEV1', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "ID={$cmd->id}&Return=-1002&CMD=DATA")
            ->assertOk();

        $this->assertSame('failed', $cmd->fresh()->status);
    }
}
