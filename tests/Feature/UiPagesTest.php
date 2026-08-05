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

    public function test_employees_mapped_table_paginates_and_honors_per_page(): void
    {
        // 11 rows against the smallest allowed page size (10) so page 1 truncates.
        foreach (range(1, 11) as $i) {
            EmployeeMap::create(['device_pin' => "5_{$i}", 'company' => '5', 'chapa' => str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'payroll_employee_id' => 1000 + $i, 'name' => sprintf('Person %02d', $i)]);
        }

        $response = $this->get('/employees?mapped_per_page=10');

        $response->assertOk();
        $response->assertSee('Person 01');
        $response->assertSee('Person 10');
        $response->assertDontSee('Person 11');
        // The tab badge shows the true total across all pages, not just this page's 10.
        $response->assertSee('bg-secondary ms-1">11<', false);
        // The per-page control reflects what was actually requested.
        $response->assertSee('value="10" selected', false);
    }

    public function test_employees_per_page_outside_the_allowed_options_falls_back_to_default(): void
    {
        // Guards the same tampering case as PerPageTest, but through the real route.
        $response = $this->get('/employees?mapped_per_page=999999');

        $response->assertOk();
        $response->assertSee('value="25" selected', false);
    }

    public function test_employees_tab_query_param_keeps_the_unmapped_tab_open_on_reload(): void
    {
        Attendance::create(['sn' => 'DEV-IN', 'table' => 'ATTLOG', 'stamp' => '1', 'employee_id' => '5_9999', 'timestamp' => now(), 'is_sync' => false]);

        $response = $this->get('/employees?tab=unmapped');

        $response->assertOk();
        // Unmapped pane is the one marked active/shown; Mapped is not.
        $response->assertSee('tab-pane fade show active" id="tab-unmapped"', false);
        $response->assertDontSee('tab-pane fade show active" id="tab-mapped"', false);
    }

    public function test_employees_search_form_carries_both_page_sizes_through_a_submit(): void
    {
        // The search form submits by GET, which rebuilds the query string from the
        // form's own fields — so without these hidden inputs a search would silently
        // reset both tables to the default page size.
        $response = $this->get('/employees?mapped_per_page=50&unmapped_per_page=10');

        $response->assertOk();
        $response->assertSee('<input type="hidden" name="mapped_per_page" value="50">', false);
        $response->assertSee('<input type="hidden" name="unmapped_per_page" value="10">', false);
        $response->assertSee('<input type="hidden" name="tab" value="mapped">', false);
    }

    public function test_the_page_size_picker_is_not_wired_through_an_inline_onchange(): void
    {
        // Regression: the picker used to navigate from an inline onchange="".
        // Inline handlers evaluate inside an implicit `with (document)`, so the
        // bare identifier `URL` resolved to `document.URL` — a string — and
        // `new URL(location.href)` threw "URL is not a constructor" before the
        // handler could navigate. The select still moved to the chosen value, so
        // every page-size picker looked like it was ignoring the click. The
        // delegated listener runs in normal scope, where `URL` is the constructor.
        $response = $this->get('/employees');

        $response->assertOk();
        // `onchange="this.form.submit()"` on the filter selects is fine — `form`
        // and `submit` aren't shadowed by document. It's specifically a *global
        // constructor* used from an inline handler that breaks, so that's what
        // this pins.
        $this->assertDoesNotMatchRegularExpression(
            '/on[a-z]+="[^"]*\bnew\s+URL\b/i',
            $response->getContent(),
            'An inline handler is calling `new URL(...)`; inside one, `URL` is document.URL (a string).'
        );
        // Each picker carries its own params for the shared listener to read.
        $response->assertSee('class="form-select form-select-sm w-auto js-per-page"', false);
        $response->assertSee('data-param="mapped_per_page"', false);
        $response->assertSee('data-page-param="mapped_page"', false);
        // One listener for all three pickers on this page, not three copies.
        $this->assertSame(1, substr_count($response->getContent(), 'js-per-page\')'));
    }
}
