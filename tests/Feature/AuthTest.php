<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_from_a_dashboard_page(): void
    {
        $this->guest()->get(route('devices.Attendance'))->assertRedirect(route('login'));
    }

    public function test_guest_can_still_reach_healthz(): void
    {
        $this->guest()->get(route('health.json'))->assertOk();
    }

    public function test_guest_can_still_reach_device_push_endpoints(): void
    {
        // No device is registered, but the point is the route isn't gated —
        // it must not redirect to /login the way a dashboard page would.
        $response = $this->guest()->get('/iclock/getrequest?SN=DOES-NOT-EXIST');

        $response->assertStatus(200);
    }

    public function test_correct_credentials_log_the_admin_in_and_reach_the_dashboard(): void
    {
        $response = $this->guest()->post(route('login.attempt'), [
            'username' => 'test-admin',
            'password' => 'test-password',
        ]);

        $response->assertRedirect(route('monitoring'));
        $this->assertTrue(session('admin_authenticated'));

        $this->get(route('devices.Attendance'))->assertOk();
    }

    public function test_wrong_password_is_rejected_and_does_not_authenticate(): void
    {
        $response = $this->guest()->post(route('login.attempt'), [
            'username' => 'test-admin',
            'password' => 'nope',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertNotTrue(session('admin_authenticated'));
    }

    public function test_login_after_a_redirect_returns_to_the_originally_requested_page(): void
    {
        $this->guest()->get(route('devices.Attendance'));

        $response = $this->post(route('login.attempt'), [
            'username' => 'test-admin',
            'password' => 'test-password',
        ]);

        $response->assertRedirect(route('devices.Attendance'));
    }

    public function test_logout_clears_the_session_and_locks_the_dashboard_again(): void
    {
        $this->post(route('logout'));

        $this->get(route('devices.Attendance'))->assertRedirect(route('login'));
    }

    public function test_login_is_refused_when_credentials_are_not_configured(): void
    {
        config(['adms.auth.username' => null, 'adms.auth.password' => null]);

        $response = $this->guest()->post(route('login.attempt'), [
            'username' => 'test-admin',
            'password' => 'test-password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertStringContainsString('not configured', session('errors')->first('username'));
    }

    public function test_login_page_redirects_to_monitoring_if_already_authenticated(): void
    {
        $this->get(route('login'))->assertRedirect(route('monitoring'));
    }
}
