<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * The single dashboard login. Credentials come from config('adms.auth')
 * (backed by ADMS_AUTH_USERNAME / ADMS_AUTH_PASSWORD in .env) — there's no
 * users table, since this is a one-admin edge box. See RequireAdminLogin.
 */
class AuthController extends Controller
{
    public function showLogin(Request $request)
    {
        if ($request->session()->get('admin_authenticated') === true) {
            return redirect()->route('monitoring');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $configuredUsername = (string) config('adms.auth.username');
        $configuredPassword = (string) config('adms.auth.password');

        if ($configuredUsername === '' || $configuredPassword === '') {
            return back()->withInput($request->only('username'))->withErrors([
                'username' => 'Login is not configured — set ADMS_AUTH_USERNAME and ADMS_AUTH_PASSWORD in .env.',
            ]);
        }

        // hash_equals for constant-time comparison of both fields.
        $matches = hash_equals($configuredUsername, (string) $request->input('username'))
            && hash_equals($configuredPassword, (string) $request->input('password'));

        if (! $matches) {
            return back()->withInput($request->only('username'))->withErrors([
                'username' => 'Invalid username or password.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('admin_authenticated', true);

        return Redirect::intended(route('monitoring'));
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_authenticated');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
