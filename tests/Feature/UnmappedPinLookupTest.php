<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnmappedPinLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_queues_user_query_for_each_source_device(): void
    {
        Device::create(['no_sn' => 'DEV-IN']);
        Device::create(['no_sn' => 'DEV-OUT']);
        Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1',
            'employee_id' => '3', 'timestamp' => now(), 'is_sync' => false,
        ]);
        Attendance::create([
            'sn' => 'DEV-OUT', 'table' => 'ATTLOG', 'stamp' => '1',
            'employee_id' => '3', 'timestamp' => now(), 'is_sync' => false,
        ]);

        $this->post(route('employees.unmapped.lookup', ['pin' => '3']))
            ->assertRedirect(route('employees.index'));

        $this->assertDatabaseHas('device_commands', [
            'device_sn' => 'DEV-IN',
            'body' => 'DATA QUERY USERINFO PIN=3',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('device_commands', [
            'device_sn' => 'DEV-OUT',
            'body' => 'DATA QUERY USERINFO PIN=3',
            'status' => 'pending',
        ]);
    }

    public function test_userinfo_response_stores_name_and_card_for_the_device_user(): void
    {
        $body = "PIN=3\tName=Juan Dela Cruz\tPri=0\tPasswd=\tCard=1996052557\n";

        $this->call('POST', '/iclock/cdata?SN=DEV-IN&table=USERINFO', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)
            ->assertOk()
            ->assertSee('OK: 1');

        $this->assertDatabaseHas('device_users', [
            'device_sn' => 'DEV-IN',
            'pin' => '3',
            'name' => 'Juan Dela Cruz',
            'card' => '1996052557',
        ]);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_lookup_does_not_duplicate_a_pending_query(): void
    {
        Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1',
            'employee_id' => '3', 'timestamp' => now(), 'is_sync' => false,
        ]);
        DeviceCommand::create([
            'device_sn' => 'DEV-IN',
            'body' => 'DATA QUERY USERINFO PIN=3',
            'status' => 'pending',
        ]);

        $this->post(route('employees.unmapped.lookup', ['pin' => '3']));

        $this->assertSame(1, DeviceCommand::where('body', 'DATA QUERY USERINFO PIN=3')->count());
    }
}
