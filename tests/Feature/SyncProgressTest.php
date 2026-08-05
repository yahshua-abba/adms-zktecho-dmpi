<?php

namespace Tests\Feature;

use App\Contracts\PayrollClient;
use App\Maintenance\LogPruner;
use App\Models\PayrollCall;
use App\Models\SyncRun;
use App\Sync\DmpiSyncLauncher;
use App\Sync\HttpPayrollClient;
use App\Sync\RosterSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePayrollClient;
use Tests\TestCase;

/**
 * A download can take ten minutes and used to show nothing between "requested"
 * and "failed". These cover that the run is tracked, that the progress reported
 * is honest (no invented percentages), that the calls to DMPI are recorded
 * without ever storing a credential, and that Stop actually stops.
 */
class SyncProgressTest extends TestCase
{
    use RefreshDatabase;

    private function roster(int $count = 1200): array
    {
        $employees = [];
        for ($i = 1; $i <= $count; $i++) {
            $employees[] = ['id' => 100000 + $i, 'company' => '5', 'chapa' => (string) $i, 'name' => "EMP {$i}"];
        }

        return $employees;
    }

    public function test_a_download_records_a_run_with_its_outcome(): void
    {
        $fake = new FakePayrollClient;
        $fake->employees = $this->roster(10);
        $this->app->instance(PayrollClient::class, $fake);

        $this->artisan('payroll:sync-roster')->assertSuccessful();

        $run = SyncRun::latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('employees', $run->part);
        $this->assertSame('succeeded', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->pid);
        $this->assertStringContainsString('Mapped employees', $run->message);
    }

    public function test_a_failed_download_is_recorded_as_failed_with_the_reason(): void
    {
        $fake = new FakePayrollClient;
        $fake->employees = []; // refused
        $this->app->instance(PayrollClient::class, $fake);

        $this->artisan('payroll:sync-roster')->assertFailed();

        $run = SyncRun::latest('id')->first();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('refusing', $run->message);
    }

    public function test_progress_is_only_a_percentage_when_there_is_something_to_count(): void
    {
        $stages = [];
        $run = SyncRun::create(['part' => 'employees', 'status' => 'running', 'started_at' => now()]);

        $fake = new FakePayrollClient;
        $fake->employees = $this->roster(1200);

        (new RosterSync($fake))->sync(function (string $stage, ?int $done, ?int $total) use (&$stages) {
            $stages[] = [$stage, $done, $total];
        });

        // Fetching reports no counts — there is no honest percentage of a reply
        // that has not arrived.
        $this->assertSame([null, null], [$stages[0][1], $stages[0][2]]);
        $this->assertStringContainsString('Asking DMPI', $stages[0][0]);

        // Saving does, because the rows are countable.
        $saving = array_values(array_filter($stages, fn ($s) => $s[0] === 'Saving employees'));
        $this->assertNotEmpty($saving);
        $this->assertSame(1200, end($saving)[2]);
        $this->assertSame(1200, end($saving)[1], 'the final report should show every row written');

        $run->forceFill(['done' => null, 'total' => null])->save();
        $this->assertNull($run->percent(), 'no total means no percentage');

        $run->forceFill(['done' => 300, 'total' => 1200])->save();
        $this->assertSame(25, $run->percent());
    }

    public function test_calls_to_dmpi_are_recorded_without_storing_any_body(): void
    {
        Http::fake([
            'payroll.test/api/api-auth/' => Http::response(['token' => 'TKN123']),
            'payroll.test/api/v2/read_employees/' => Http::response(['employees' => [
                ['id' => 1, 'company' => 5, 'code' => '1', 'firstname' => 'A', 'lastname' => 'B'],
            ]]),
        ]);
        config(['payroll.base_url' => 'https://payroll.test/']);

        $client = new HttpPayrollClient('https://payroll.test/', 'svc@dmpi', 'hunter2', retries: 1, retryBaseMs: 0);
        $client->fetchEmployees();

        $calls = PayrollCall::orderBy('id')->get();
        $this->assertCount(2, $calls, 'the login and the read should both be recorded');

        $this->assertSame('/api/api-auth/', $calls[0]->endpoint);
        $this->assertSame('/api/v2/read_employees/', $calls[1]->endpoint);
        $this->assertSame('ok', $calls[1]->outcome);
        $this->assertSame(200, $calls[1]->status_code);
        $this->assertGreaterThan(0, $calls[1]->response_bytes);

        // The password must not have been written anywhere.
        foreach ($calls as $call) {
            $this->assertStringNotContainsString('hunter2', json_encode($call->toArray()));
        }
    }

    public function test_a_failed_call_records_why(): void
    {
        config(['payroll.base_url' => 'https://payroll.test/']);
        Http::fake(['payroll.test/*' => Http::response('boom', 500)]);

        $client = new HttpPayrollClient('https://payroll.test/', 'u', 'p', retries: 1, retryBaseMs: 0);
        try {
            $client->fetchEmployees();
        } catch (\Throwable $e) {
            // login refusal is expected here
        }

        $call = PayrollCall::latest('id')->first();
        $this->assertSame('http_error', $call->outcome);
        $this->assertSame(500, $call->status_code);
    }

    public function test_calls_to_other_hosts_are_not_recorded(): void
    {
        config(['payroll.base_url' => 'https://payroll.test/']);
        Http::fake(['example.com/*' => Http::response('hi')]);

        Http::get('https://example.com/whatever');

        $this->assertSame(0, PayrollCall::count());
    }

    public function test_status_endpoint_reports_idle_when_nothing_is_running(): void
    {
        $this->getJson(route('dmpi.status'))
            ->assertOk()
            ->assertJson(['running' => false]);
    }

    public function test_status_endpoint_reports_a_running_download(): void
    {
        SyncRun::create([
            'part' => 'assignments', 'status' => 'running', 'stage' => 'Saving assignments',
            'done' => 500, 'total' => 2000, 'pid' => getmypid(), 'started_at' => now(),
        ]);

        $this->getJson(route('dmpi.status'))
            ->assertOk()
            ->assertJson([
                'running' => true,
                'what' => 'Downloading assignments',
                'stage' => 'Saving assignments',
                'percent' => 25,
            ]);
    }

    public function test_a_run_whose_process_died_is_closed_rather_than_shown_forever(): void
    {
        SyncRun::create([
            'part' => 'employees', 'status' => 'running', 'stage' => 'Waiting',
            'pid' => 999999, // no such process
            'started_at' => now(),
        ]);

        $this->getJson(route('dmpi.status'))->assertOk()->assertJson(['running' => false]);

        $this->assertSame('failed', SyncRun::latest('id')->first()->status);
    }

    public function test_stop_closes_the_run_and_frees_the_lock(): void
    {
        $lock = Cache::lock(DmpiSyncLauncher::LOCK, 600);
        $this->assertTrue($lock->get());

        SyncRun::create([
            'part' => 'devices', 'status' => 'running', 'stage' => 'Waiting for DMPI',
            'pid' => 999999, // not our process, so nothing is signalled
            'started_at' => now(),
        ]);

        $this->post(route('dmpi.stop'))->assertRedirect()->assertSessionHas('success');

        $this->assertSame('cancelled', SyncRun::latest('id')->first()->status);

        // A killed process runs no cleanup, so stopping must free the lock itself —
        // otherwise every later download is blocked until the TTL expires.
        $again = Cache::lock(DmpiSyncLauncher::LOCK, 5);
        $this->assertTrue($again->get(), 'the lock should have been force-released');
        $again->release();
    }

    public function test_stop_admits_it_when_the_process_will_not_die(): void
    {
        $lock = Cache::lock(DmpiSyncLauncher::LOCK, 600);
        $this->assertTrue($lock->get());

        // The test process itself: alive, matches our command line (phpunit runs
        // artisan-adjacent), and cannot be killed by this request. Stands in for a
        // download started by a different user, which the web server may not signal.
        SyncRun::create([
            'part' => 'devices', 'status' => 'running', 'pid' => getmypid(), 'started_at' => now(),
        ]);

        $response = $this->post(route('dmpi.stop'))->assertRedirect();

        if (session('error')) {
            // Could not stop it: the run must stay open and the lock stay held,
            // otherwise a second download starts alongside the one still running.
            $this->assertSame('running', SyncRun::latest('id')->first()->status);
            $again = Cache::lock(DmpiSyncLauncher::LOCK, 5);
            $this->assertFalse($again->get(), 'the lock must not be freed while the process lives');
        } else {
            // The environment let us signal it; then it must genuinely be closed.
            $this->assertSame('cancelled', SyncRun::latest('id')->first()->status);
        }

        $lock->forceRelease();
        $response->assertRedirect();
    }

    public function test_an_orphaned_lock_is_reclaimed_rather_than_blocking_forever(): void
    {
        // What a fatal error leaves behind: the lock still held, but no live run.
        // PHP skips finally{} on a fatal, and running out of memory decoding DMPI's
        // reply is not hypothetical — it happened. Left alone, every later download
        // was refused for 30 minutes while the dashboard showed nothing running,
        // which reads as the button doing nothing at all.
        $held = Cache::lock(DmpiSyncLauncher::LOCK, 1800);
        $this->assertTrue($held->get());

        SyncRun::create([
            'part' => 'devices', 'status' => 'running', 'pid' => 999999, // dead
            'started_at' => now(),
        ]);

        $launcher = new DmpiSyncLauncher;

        $this->assertFalse($launcher->isRunning(), 'an orphaned lock must not block downloads');
        $this->assertSame('failed', SyncRun::latest('id')->first()->status);

        $again = Cache::lock(DmpiSyncLauncher::LOCK, 5);
        $this->assertTrue($again->get(), 'the orphaned lock should have been reclaimed');
        $again->release();
    }

    public function test_a_lock_held_by_a_live_download_is_respected(): void
    {
        $held = Cache::lock(DmpiSyncLauncher::LOCK, 600);
        $this->assertTrue($held->get());

        SyncRun::create([
            'part' => 'employees', 'status' => 'running', 'pid' => getmypid(), // alive
            'started_at' => now(),
        ]);

        $this->assertTrue((new DmpiSyncLauncher)->isRunning(), 'a genuinely running download must still block a second one');

        $held->forceRelease();
    }

    public function test_stop_says_so_when_nothing_is_running(): void
    {
        $this->post(route('dmpi.stop'))->assertRedirect()->assertSessionHas('error');
    }

    public function test_calls_page_renders_and_filters(): void
    {
        PayrollCall::create([
            'method' => 'POST', 'endpoint' => '/api/v2/read_device_info/', 'outcome' => 'failed',
            'error' => 'cURL error 28: Operation timed out', 'created_at' => now(),
        ]);
        PayrollCall::create([
            'method' => 'POST', 'endpoint' => '/api/v2/read_employees/', 'outcome' => 'ok',
            'status_code' => 200, 'response_bytes' => 2623330, 'duration_ms' => 20300, 'created_at' => now(),
        ]);

        $this->get(route('dmpi.calls'))
            ->assertOk()
            ->assertSee('read_device_info')
            ->assertSee('read_employees')
            ->assertSee('2.5 MB')
            ->assertSee('20.3 s');

        $this->get(route('dmpi.calls', ['outcome' => 'failed']))
            ->assertOk()
            ->assertSee('read_device_info')
            ->assertDontSee('read_employees');
    }

    public function test_the_call_log_is_pruned_but_a_running_download_is_not_forgotten(): void
    {
        $old = now()->subDays(60);

        PayrollCall::create(['method' => 'POST', 'endpoint' => '/api/v2/read_employees/', 'outcome' => 'ok', 'created_at' => $old]);
        PayrollCall::create(['method' => 'POST', 'endpoint' => '/api/v2/read_employees/', 'outcome' => 'ok', 'created_at' => now()]);

        $finished = SyncRun::create(['part' => 'employees', 'status' => 'succeeded', 'started_at' => $old]);
        $finished->forceFill(['created_at' => $old])->save();

        $stillGoing = SyncRun::create(['part' => 'devices', 'status' => 'running', 'pid' => getmypid(), 'started_at' => $old]);
        $stillGoing->forceFill(['created_at' => $old])->save();

        LogPruner::prune(30);

        $this->assertSame(1, PayrollCall::count(), 'only the old call should go');
        $this->assertNull(SyncRun::find($finished->id));
        $this->assertNotNull(SyncRun::find($stillGoing->id), 'a download still in flight must not be pruned out from under the dashboard');
    }

    public function test_a_call_to_the_bare_host_is_recorded_as_a_root_path(): void
    {
        // The health check pings the base URL, which has no path at all. That must
        // not fall back to printing the whole URL in the endpoint column.
        config(['payroll.base_url' => 'https://payroll.test']);
        Http::fake(['payroll.test' => Http::response('ok')]);

        Http::get('https://payroll.test');

        $this->assertSame('/', PayrollCall::latest('id')->first()->endpoint);
    }

    public function test_the_progress_strip_is_on_the_employees_page(): void
    {
        $this->get(route('employees.index'))
            ->assertOk()
            ->assertSee('sync-progress')
            ->assertSee(route('dmpi.stop'));
    }
}
