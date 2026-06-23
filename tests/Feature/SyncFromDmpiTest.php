<?php

namespace Tests\Feature;

use App\Contracts\PayrollClient;
use App\Models\EmployeeMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePayrollClient;
use Tests\TestCase;

class SyncFromDmpiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_from_dmpi_button_pulls_the_roster(): void
    {
        $this->app->instance(PayrollClient::class, $fake = new FakePayrollClient());
        $fake->employees = [
            ['id' => 35042, 'company' => '267', 'chapa' => '123123', 'name' => 'BAYRON, RON MICHAEL', 'rfid' => '1996052557'],
        ];

        $this->post(route('dmpi.sync'))->assertRedirect();

        $map = EmployeeMap::where('payroll_employee_id', 35042)->first();
        $this->assertNotNull($map);
        $this->assertSame('267_123123', $map->device_pin);
        $this->assertSame('1996052557', $map->rfid);
    }

    public function test_employees_page_shows_the_sync_from_dmpi_button(): void
    {
        $this->get(route('employees.index'))
            ->assertOk()
            ->assertSee('Sync from DMPI');
    }
}
