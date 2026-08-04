<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * The dashboard sits behind a single login (see RequireAdminLogin), and
     * almost every existing feature test exercises dashboard routes assuming
     * they're already "in". Auto-authenticate every test by default so none of
     * them had to be individually updated; tests that specifically cover the
     * logged-out path call guest() to undo this first.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withSession(['admin_authenticated' => true]);
    }

    /** Drop back to a logged-out session, for tests covering the login wall itself. */
    protected function guest(): static
    {
        $this->app['session']->forget('admin_authenticated');

        return $this;
    }
}
