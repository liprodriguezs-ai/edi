<?php

namespace App\Providers;

use App\Listeners\StripeWebhookListener;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Services\AccountOwnerResolver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Cashier::ignoreRoutes();

        // scoped(), never singleton(): the per-tenant memo inside the resolver
        // must span exactly one request (Octane-safe). Precedent:
        // Modules/Collections/app/Providers/CollectionsServiceProvider.php:56.
        $this->app->scoped(AccountOwnerResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        // Cashier keeps the local subscriptions table in sync on its own; this
        // listener owns the tenant entitlement/status transitions driven by
        // Stripe events (checkout completed, subscription updated/deleted, invoice
        // payment failed). See App\Listeners\StripeWebhookListener for the tenancy
        // contract (resolves tenants BY ID from event metadata).
        Event::listen(WebhookReceived::class, StripeWebhookListener::class);
        if (config('app.env') == 'local') {
            /* DB::listen(function ($query) {
                Log::info(
                    $query->sql,
                    [
                        'bindings' => $query->bindings,
                        'time' => $query->time,
                    ]
                );
            }); */
        }
        /*  resolve(EngineManager::class)->extend('mysql', function () {
            return new MySqlSearchEngine;
        }); */
    }
}
