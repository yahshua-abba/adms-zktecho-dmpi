<?php

namespace App\Providers;

use App\Health\ScheduledTaskRecorder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Subscribers, which get the dispatcher and register themselves.
     *
     * @var array<int, class-string>
     */
    protected $subscribe = [
        // Records every scheduled job run. A subscriber rather than four separate
        // listener entries because it has to hold state between the start and end
        // of a run, which only one long-lived instance can do.
        ScheduledTaskRecorder::class,
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
