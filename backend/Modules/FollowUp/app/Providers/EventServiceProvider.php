<?php

namespace Modules\FollowUp\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\FollowUp\Listeners\CreateFollowUpsFromScreening;
use Modules\Screening\Events\ScreeningCompleted;

/**
 * design D1 — the listener runs SYNCHRONOUSLY, inside the same transaction
 * ScreeningLifecycleService::complete() opened, because Laravel dispatches
 * non-queued listeners synchronously by default. This is deliberate: a
 * completed screening must never exist without its conduct.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        ScreeningCompleted::class => [
            CreateFollowUpsFromScreening::class,
        ],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = false;
}
