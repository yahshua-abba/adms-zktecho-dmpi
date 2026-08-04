<?php

namespace Tests\Unit;

use App\Support\PerPage;
use Tests\TestCase;

class PerPageTest extends TestCase
{
    public function test_resolve_accepts_an_allowed_option(): void
    {
        $this->assertSame(100, PerPage::resolve(100));
    }

    public function test_resolve_falls_back_to_default_for_null(): void
    {
        $this->assertSame(PerPage::DEFAULT, PerPage::resolve(null));
    }

    public function test_resolve_falls_back_to_default_for_a_value_not_in_the_option_list(): void
    {
        // Guards against a tampered query string forcing an unbounded dump —
        // e.g. ?per_page=999999 must not sail past the 500 cap.
        $this->assertSame(PerPage::DEFAULT, PerPage::resolve(999999));
        $this->assertSame(PerPage::DEFAULT, PerPage::resolve(0));
        $this->assertSame(PerPage::DEFAULT, PerPage::resolve(-5));
    }

    public function test_the_option_list_is_capped_at_500(): void
    {
        $this->assertSame(500, max(PerPage::OPTIONS));
    }
}
