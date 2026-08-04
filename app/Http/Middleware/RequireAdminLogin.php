<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the dashboard behind the single .env-configured admin login.
 *
 * There's no users table — see config('adms.auth') — so this checks a plain
 * session flag rather than Laravel's Auth facade/guard system. Device
 * push-protocol routes (/iclock/*) and the machine-readable /healthz endpoint
 * are intentionally left outside this middleware; see routes/web.php.
 */
class RequireAdminLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('admin_authenticated') !== true) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
