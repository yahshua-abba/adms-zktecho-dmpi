<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path redirects to the monitoring page.
     */
    public function test_the_root_redirects_to_monitoring(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('monitoring'));
    }
}
