<?php

namespace App\Providers;

use App\Contracts\PayrollClient;
use App\Sync\HttpPayrollClient;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;

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
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrap();
    }
}
