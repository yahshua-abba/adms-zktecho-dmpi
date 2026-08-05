<?php

namespace App\Providers;

use App\Contracts\PayrollClient;
use App\Sync\HttpPayrollClient;
use App\Sync\PayrollCallRecorder;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PayrollClient::class, function ($app) {
            $config = $app['config']['payroll'];

            return new HttpPayrollClient(
                baseUrl: $config['base_url'],
                username: $config['username'],
                password: $config['password'],
                userAgent: $config['user_agent'],
                timeout: $config['timeout'],
                retries: $config['retries'],
                retryBaseMs: $config['retry_base_ms'],
            );
        });

        $this->app->singleton(PayrollCallRecorder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrap();

        // Record every call to DMPI. Listening to the HTTP client's own events
        // rather than wrapping HttpPayrollClient means retries and re-auths each
        // show up as their own row — which is exactly the detail you want when a
        // download has been silent for nine minutes.
        Event::listen(RequestSending::class, [PayrollCallRecorder::class, 'sending']);
        Event::listen(ResponseReceived::class, [PayrollCallRecorder::class, 'received']);
        Event::listen(ConnectionFailed::class, [PayrollCallRecorder::class, 'failed']);
    }
}
