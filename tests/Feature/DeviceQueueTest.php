<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\EmployeeMap;
use App\Queries\DeviceQueue;
use App\Sync\CommandQueue;
use App\Sync\EnrollmentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceQueueTest extends TestCase
{
    use RefreshDatabase;

    private function device(?string $code = 'C1'): Device
    {
        return Device::create(['no_sn' => 'DEV1', 'nama' => 'Gowning-1', 'direction' => 'in', 'payroll_device_code' => $code]);
    }

    private function command(string $body, string $status = 'pending'): DeviceCommand
    {
        return DeviceCommand::create(['device_sn' => 'DEV1', 'body' => $body, 'status' => $status]);
    }

    private function add(string $pin): DeviceCommand
    {
        return $this->command("DATA UPDATE USERINFO PIN={$pin}\tName=Someone\tPri=0\tCard=[1]");
    }

    private function remove(string $pin): DeviceCommand
    {
        return $this->command("DATA DELETE USERINFO PIN={$pin}");
    }

    public function test_counts_group_by_state(): void
    {
        $device = $this->device();
        $this->add('5_1');
        $this->remove('5_2');
        $this->command('DATA DELETE USERINFO PIN=5_3', 'sent');
        $this->command('DATA DELETE USERINFO PIN=5_4', 'done');
        $this->command('DATA DELETE USERINFO PIN=5_5', 'failed');

        $counts = DeviceQueue::counts($device);

        $this->assertSame(2, $counts[DeviceQueue::PENDING]);
        $this->assertSame(1, $counts[DeviceQueue::SENT]);
        $this->assertSame(1, $counts[DeviceQueue::DONE]);
        $this->assertSame(1, $counts[DeviceQueue::FAILED]);
        $this->assertSame(5, $counts['total']);
    }

    public function test_progress_separates_delivery_from_device_responses(): void
    {
        $device = $this->device();
        $this->add('5_1');
        $this->remove('5_2');
        $this->command('DATA UPDATE USERINFO PIN=5_3', 'sent');
        $this->command('DATA UPDATE USERINFO PIN=5_4', 'done');
        $this->command('DATA UPDATE USERINFO PIN=5_5', 'failed');

        $progress = DeviceQueue::progress($device);

        $this->assertSame(5, $progress['total']);
        $this->assertSame(3, $progress['delivered']);
        $this->assertSame(2, $progress['responded']);
        $this->assertSame(60, $progress['delivery_percent']);
        $this->assertSame(40, $progress['response_percent']);
    }

    public function test_commands_decode_the_pin_action_and_person(): void
    {
        $device = $this->device();
        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn',
        ]);
        $this->remove('5_4968');

        $row = DeviceQueue::commands($device)->items()[0];

        $this->assertSame('5_4968', $row->pin);
        $this->assertSame(DeviceQueue::REMOVE, $row->action);
        $this->assertSame('Rubelyn', $row->person);
        $this->assertTrue($row->cancellable);
    }

    public function test_a_sent_command_is_not_cancellable(): void
    {
        $device = $this->device();
        $this->command('DATA DELETE USERINFO PIN=5_1', 'sent');

        $this->assertFalse(DeviceQueue::commands($device)->items()[0]->cancellable);
    }

    public function test_filters_by_state_and_action(): void
    {
        $device = $this->device();
        $this->add('5_1');
        $this->remove('5_2');
        $this->command('DATA DELETE USERINFO PIN=5_3', 'done');

        $this->assertCount(2, DeviceQueue::commands($device, ['status' => DeviceQueue::PENDING])->items());
        $this->assertCount(2, DeviceQueue::commands($device, ['action' => DeviceQueue::REMOVE])->items());
        $this->assertCount(1, DeviceQueue::commands($device, ['action' => DeviceQueue::ADD])->items());
    }

    public function test_cancelling_removes_only_pending_commands(): void
    {
        $device = $this->device();
        $this->remove('5_1');
        $this->remove('5_2');
        $sent = $this->command('DATA DELETE USERINFO PIN=5_3', 'sent');

        $result = (new CommandQueue)->cancel($device);

        $this->assertSame(2, $result['cancelled']);
        $this->assertSame(1, DeviceCommand::count());
        $this->assertDatabaseHas('device_commands', ['id' => $sent->id, 'status' => 'sent']);
    }

    /**
     * A `sent` instruction is already in the device's hands. Deleting the row
     * would destroy the record of what went out while changing nothing on the
     * machine — the screen would understate the damage rather than undo it.
     */
    public function test_picking_a_sent_command_reports_it_as_skipped_not_cancelled(): void
    {
        $device = $this->device();
        $sent = $this->command('DATA DELETE USERINFO PIN=5_1', 'sent');
        $pending = $this->remove('5_2');

        $result = (new CommandQueue)->cancel($device, [$sent->id, $pending->id]);

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseHas('device_commands', ['id' => $sent->id]);
        $this->assertDatabaseMissing('device_commands', ['id' => $pending->id]);
    }

    /**
     * The reconciler writes device_enrollment when it QUEUES an add, not when the
     * device takes it. Cancelling the add must drop that row too, or the next
     * reconcile sees nothing owed and the person is silently absent for good.
     */
    public function test_cancelling_an_add_forgets_that_the_person_was_sent(): void
    {
        $device = $this->device();
        DeviceEnrollment::create(['device_sn' => 'DEV1', 'pin' => '5_4968', 'name' => 'Rubelyn', 'card' => '[1]']);
        $this->add('5_4968');

        $result = (new CommandQueue)->cancel($device);

        $this->assertSame(1, $result['requeued']);
        $this->assertDatabaseMissing('device_enrollment', ['device_sn' => 'DEV1', 'pin' => '5_4968']);
    }

    /** The repair above must actually put the person back in the next run's diff. */
    public function test_a_cancelled_add_is_queued_again_by_the_next_reconcile(): void
    {
        $device = $this->device();
        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn', 'rfid' => '55:2D:E3:D3',
        ]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 48213]);

        (new EnrollmentReconciler)->reconcileDevice('DEV1');
        $this->assertSame(1, DeviceCommand::where('status', 'pending')->count());

        (new CommandQueue)->cancel($device);
        $this->assertSame(0, DeviceCommand::count());

        (new EnrollmentReconciler)->reconcileDevice('DEV1');

        $this->assertSame(1, DeviceCommand::where('status', 'pending')->count());
        $this->assertDatabaseHas('device_enrollment', ['device_sn' => 'DEV1', 'pin' => '5_4968']);
    }

    /**
     * A cancelled removal needs no repair: the reconciler already dropped the
     * enrollment row when it queued the delete, which is exactly the state
     * "still on the machine, no longer managed here" wants.
     */
    public function test_cancelling_a_removal_does_not_touch_enrollment(): void
    {
        $device = $this->device();
        DeviceEnrollment::create(['device_sn' => 'DEV1', 'pin' => '5_1', 'name' => 'Kept', 'card' => '[1]']);
        $this->remove('5_2');

        $result = (new CommandQueue)->cancel($device);

        $this->assertSame(0, $result['requeued']);
        $this->assertDatabaseHas('device_enrollment', ['device_sn' => 'DEV1', 'pin' => '5_1']);
    }

    /**
     * The device flips rows pending -> sent in `getrequest` whenever it polls, so
     * a row read as pending can be in its hands before the delete runs. Deleting
     * it on the strength of the earlier read would destroy the record of what
     * went out, and — for an add — drop the enrollment row while the device is
     * being told to add that person.
     *
     * DB::listen fires after each query, so flipping the row when the candidate
     * SELECT completes lands the test in exactly that window.
     */
    public function test_a_command_the_device_collects_mid_cancel_survives(): void
    {
        $device = $this->device();
        DeviceEnrollment::create(['device_sn' => 'DEV1', 'pin' => '5_4968', 'name' => 'Rubelyn', 'card' => '[1]']);
        $command = $this->add('5_4968');

        $fired = false;
        DB::listen(function ($query) use (&$fired, $command) {
            if ($fired || ! str_contains($query->sql, 'device_commands')) {
                return;
            }
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            $fired = true;
            DB::table('device_commands')->where('id', $command->id)->update(['status' => 'sent']);
        });

        $result = (new CommandQueue)->cancel($device);

        $this->assertTrue($fired, 'the race was never simulated — the test no longer proves anything');
        $this->assertSame(0, $result['cancelled']);
        $this->assertSame(1, $result['skipped'], 'a row that raced away is still "asked for, not cancelled"');
        $this->assertSame(0, $result['requeued']);

        $this->assertDatabaseHas('device_commands', ['id' => $command->id, 'status' => 'sent']);
        // The enrollment repair must not run for a row still on its way out.
        $this->assertDatabaseHas('device_enrollment', ['device_sn' => 'DEV1', 'pin' => '5_4968']);
    }

    public function test_cancelling_leaves_other_devices_alone(): void
    {
        $device = $this->device();
        Device::create(['no_sn' => 'DEV2']);
        $this->remove('5_1');
        DeviceCommand::create(['device_sn' => 'DEV2', 'body' => 'DATA DELETE USERINFO PIN=5_9', 'status' => 'pending']);

        (new CommandQueue)->cancel($device);

        $this->assertSame(1, DeviceCommand::where('device_sn', 'DEV2')->count());
    }

    // ─── The screen ───

    public function test_queue_screen_lists_the_commands(): void
    {
        $device = $this->device();
        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn',
        ]);
        $this->remove('5_4968');

        $this->get(route('devices.queue', $device->id))
            ->assertOk()
            ->assertSee('Remove from clock')
            ->assertSee('Rubelyn')
            ->assertSee('Delivery progress')
            ->assertSee('Device responses')
            ->assertSee('Cancel all')
            ->assertSee('data-cancel-all-count', false);
    }

    public function test_live_queue_status_returns_progress_and_requested_command_responses(): void
    {
        $device = $this->device();
        $done = DeviceCommand::create([
            'device_sn' => 'DEV1',
            'body' => 'DATA UPDATE USERINFO PIN=5_1',
            'status' => 'done',
            'return_code' => 0,
            'response' => 'ID=10&Return=0&CMD=DATA',
            'verification_status' => 'present',
            'verified_at' => now(),
            'sent_at' => now()->subSecond(),
            'done_at' => now(),
        ]);
        $other = DeviceCommand::create([
            'device_sn' => 'OTHER',
            'body' => 'DATA UPDATE USERINFO PIN=5_2',
            'status' => 'failed',
            'return_code' => -1,
        ]);

        $this->getJson(route('devices.queue.status', [
            'device' => $device->id,
            'ids' => "{$done->id},{$other->id}",
        ]))
            ->assertOk()
            ->assertJsonPath('progress.total', 1)
            ->assertJsonPath('progress.delivered', 1)
            ->assertJsonPath('progress.responded', 1)
            ->assertJsonPath("commands.{$done->id}.status", 'done')
            ->assertJsonPath("commands.{$done->id}.response", 'ID=10&Return=0&CMD=DATA')
            ->assertJsonPath("commands.{$done->id}.response_summary", 'Verified present on clock')
            ->assertJsonPath("commands.{$done->id}.can_retry", false)
            ->assertJsonMissingPath("commands.{$other->id}");
    }

    public function test_live_queue_status_requires_login(): void
    {
        $device = $this->device();

        $this->guest()->getJson(route('devices.queue.status', $device->id))->assertRedirect(route('login'));
    }

    public function test_retrying_an_unconfirmed_add_queues_a_fresh_enrollment(): void
    {
        $device = $this->device();
        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn', 'rfid' => '55:2D:E3:D3',
        ]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 48213]);
        $unconfirmed = $this->command("DATA UPDATE USERINFO PIN=5_4968\tName=Rubelyn\tPri=0\tCard=[552DE3D3]", 'sent');

        $this->post(route('devices.queue.retry', [$device->id, $unconfirmed->id]))
            ->assertRedirect(route('devices.queue', $device->id))
            ->assertSessionHas('success');

        $this->assertSame(1, DeviceCommand::where('status', 'sent')->count());
        $this->assertSame(1, DeviceCommand::where('status', 'pending')->count());
        $this->assertDatabaseHas('device_commands', [
            'device_sn' => 'DEV1',
            'status' => 'pending',
            'body' => "DATA UPDATE USERINFO PIN=5_4968\tName=Rubelyn\tPri=0\tCard=[552DE3D3]",
        ]);
    }

    public function test_a_pin_verified_as_missing_can_be_retried(): void
    {
        $device = $this->device();
        EmployeeMap::create([
            'device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968',
            'payroll_employee_id' => 48213, 'name' => 'Rubelyn', 'rfid' => '55:2D:E3:D3',
        ]);
        DeviceAssignment::create(['device_code' => 'C1', 'payroll_employee_id' => 48213]);
        $missing = DeviceCommand::create([
            'device_sn' => 'DEV1',
            'body' => 'DATA UPDATE USERINFO PIN=5_4968',
            'status' => 'failed',
            'verification_status' => 'absent',
            'verified_at' => now(),
        ]);

        $this->post(route('devices.queue.retry', [$device->id, $missing->id]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('device_commands', [
            'device_sn' => 'DEV1',
            'status' => 'pending',
            'body' => "DATA UPDATE USERINFO PIN=5_4968\tName=Rubelyn\tPri=0\tCard=[552DE3D3]",
        ]);
    }

    public function test_an_unconfirmed_add_can_queue_one_pin_verification(): void
    {
        $device = $this->device();
        $unconfirmed = $this->command('DATA UPDATE USERINFO PIN=5_4968', 'sent');

        $this->post(route('devices.queue.verify', [$device->id, $unconfirmed->id]))
            ->assertSessionHas('success');
        $this->post(route('devices.queue.verify', [$device->id, $unconfirmed->id]))
            ->assertSessionHas('success');

        $this->assertSame(1, DeviceCommand::where('body', 'DATA QUERY USERINFO PIN=5_4968')->count());
        $this->assertDatabaseHas('device_commands', [
            'device_sn' => 'DEV1',
            'body' => 'DATA QUERY USERINFO PIN=5_4968',
            'status' => 'pending',
            'source_command_id' => $unconfirmed->id,
        ]);
    }

    public function test_retry_and_verify_reject_a_removal_or_another_clocks_command(): void
    {
        $device = $this->device();
        $removal = $this->command('DATA DELETE USERINFO PIN=5_4968', 'sent');
        $other = DeviceCommand::create([
            'device_sn' => 'OTHER',
            'body' => 'DATA UPDATE USERINFO PIN=5_4968',
            'status' => 'sent',
        ]);

        $this->post(route('devices.queue.retry', [$device->id, $removal->id]))->assertSessionHas('error');
        $this->post(route('devices.queue.verify', [$device->id, $other->id]))->assertSessionHas('error');

        $this->assertSame(2, DeviceCommand::count());
    }

    public function test_unconfirmed_add_shows_retry_and_verify_actions(): void
    {
        $device = $this->device();
        $unconfirmed = $this->command('DATA UPDATE USERINFO PIN=5_4968', 'sent');
        $this->command('DATA DELETE USERINFO PIN=5_5000', 'sent');

        $this->get(route('devices.queue', $device->id))
            ->assertOk()
            ->assertSee('Retry enrollment')
            ->assertSee('Verify on clock')
            ->assertSee(route('devices.queue.retry', [$device->id, $unconfirmed->id]), false)
            ->assertSee(route('devices.queue.verify', [$device->id, $unconfirmed->id]), false);
    }

    public function test_retry_and_verify_require_login(): void
    {
        $device = $this->device();
        $unconfirmed = $this->command('DATA UPDATE USERINFO PIN=5_4968', 'sent');

        $this->guest()->post(route('devices.queue.retry', [$device->id, $unconfirmed->id]))
            ->assertRedirect(route('login'));
        $this->guest()->post(route('devices.queue.verify', [$device->id, $unconfirmed->id]))
            ->assertRedirect(route('login'));

        $this->assertSame(1, DeviceCommand::count());
    }

    public function test_queue_screen_requires_login(): void
    {
        $device = $this->device();

        $this->guest()->get(route('devices.queue', $device->id))->assertRedirect(route('login'));
    }

    /**
     * Asserted separately from the GET above: this is the destructive half, and
     * its protection is currently structural (both routes sit in the same
     * auth.admin group). Moving one out would otherwise be caught by nothing.
     */
    public function test_cancelling_requires_login(): void
    {
        $device = $this->device();
        $command = $this->remove('5_1');

        $this->guest()->post(route('devices.queue.cancel', $device->id))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('device_commands', ['id' => $command->id]);
    }

    public function test_cancel_all_through_the_screen(): void
    {
        $device = $this->device();
        $this->remove('5_1');
        $this->remove('5_2');

        $this->post(route('devices.queue.cancel', $device->id))
            ->assertRedirect(route('devices.queue', $device->id))
            ->assertSessionHas('success');

        $this->assertSame(0, DeviceCommand::count());
    }

    public function test_cancel_picked_through_the_screen(): void
    {
        $device = $this->device();
        $keep = $this->remove('5_1');
        $drop = $this->remove('5_2');

        $this->post(route('devices.queue.cancel', $device->id), ['ids' => [$drop->id]])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('device_commands', ['id' => $keep->id]);
        $this->assertDatabaseMissing('device_commands', ['id' => $drop->id]);
    }

    public function test_cancelling_nothing_reports_it_rather_than_claiming_success(): void
    {
        $device = $this->device();
        $this->command('DATA DELETE USERINFO PIN=5_1', 'sent');

        $this->post(route('devices.queue.cancel', $device->id))
            ->assertSessionHas('error');
    }

    public function test_a_cancellation_is_recorded_in_the_activity_log(): void
    {
        $device = $this->device();
        $this->remove('5_1');

        $this->post(route('devices.queue.cancel', $device->id));

        $entry = ActivityLog::where('event', 'device.queue-cancelled')->first();
        $this->assertNotNull($entry);
        $this->assertSame('warning', $entry->level);
    }

    public function test_the_queued_badge_on_the_devices_list_links_to_the_queue(): void
    {
        $device = $this->device();
        $this->remove('5_1');

        $this->get(route('devices.index'))
            ->assertOk()
            ->assertSee(route('devices.queue', $device->id), false);
    }
}
