<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\EmployeeMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_loads_with_stats_and_health(): void
    {
        Device::create(['no_sn' => 'A', 'online' => now()->subMinutes(1)]);

        $response = $this->get('/monitoring');

        $response->assertOk();
        $response->assertSee('Monitoring');
        $response->assertSee('Devices online');   // stats
        $response->assertSee('Unmapped PINs');     // stats
        $response->assertSee('Database');          // health check
        $response->assertSee('Start scheduler');   // health action
    }

    public function test_dashboard_and_health_redirect_to_monitoring(): void
    {
        $this->get('/dashboard')->assertRedirect(route('monitoring'));
        $this->get('/health')->assertRedirect(route('monitoring'));
    }

    public function test_help_page_renders_with_key_sections(): void
    {
        $response = $this->get('/help');

        $response->assertOk();
        $response->assertSee('Architecture');
        $response->assertSee('Data flow');
        $response->assertSee('Troubleshooting');
        $response->assertSee('Device PIN');
    }

    public function test_employees_page_lists_mapped_and_unmapped(): void
    {
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213, 'name' => 'ABABA, Rubelyn', 'rfid' => '1996052557']);
        Attendance::create([
            'sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1',
            'employee_id' => '5_9999', 'timestamp' => now(), 'is_sync' => false,
        ]);

        $response = $this->get('/employees');

        $response->assertOk();
        $response->assertSee('ABABA, Rubelyn');
        $response->assertSee('RFID Card');
        $response->assertSee('1996052557'); // RFID shown for mapped employee
        $response->assertSee('5_9999'); // unmapped PIN surfaced
    }

    public function test_employees_search_filters_the_roster(): void
    {
        EmployeeMap::create(['device_pin' => '5_4968', 'company' => '5', 'chapa' => '4968', 'payroll_employee_id' => 48213, 'name' => 'ABABA, Rubelyn']);
        EmployeeMap::create(['device_pin' => '5_9343', 'company' => '5', 'chapa' => '9343', 'payroll_employee_id' => 51234, 'name' => 'CRUZ, Juan']);

        $response = $this->get('/employees?search=rube');

        $response->assertOk();
        $response->assertSee('ABABA, Rubelyn');
        $response->assertDontSee('CRUZ, Juan');
    }
}
