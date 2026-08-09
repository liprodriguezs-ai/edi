<?php

namespace Modules\AmenityReservations\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\AmenityReservations\Console\Commands\CancelExpiredReservationsCommand;
use Modules\AmenityReservations\Handlers\AmenityReservationEarmarkHandler;
use Modules\AmenityReservations\Http\Middleware\EnsureAmenityReservationsEnabled;
use Modules\AmenityReservations\Observers\AmenityReservationChargeApplicationObserver;
use Modules\AmenityReservations\Observers\AmenityReservationPaymentRejectionObserver;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentApplication;
use Modules\Collections\Support\PaymentEarmarkRegistry;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AmenityReservationsServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'AmenityReservations';

    protected string $nameLower = 'amenityreservations';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->registerMiddlewareAliases();

        // S8: accounting bridge Collections → AmenityReservations. Registered
        // HERE so Collections stays untouched (mirrors ProjectsServiceProvider
        // registering ProjectChargeApplicationObserver).
        PaymentApplication::observe(AmenityReservationChargeApplicationObserver::class);

        // S10: fires amenity_reservation.payment_rejected when the treasurer
        // rejects an amenity earmark payment. Registered HERE for the same
        // reason as the observer above — Collections\PaymentService::reject()
        // is never touched.
        Payment::observe(AmenityReservationPaymentRejectionObserver::class);

        // S8: earmarked payments — a payment tagged amenity_reservation creates
        // its own standalone charge and pins the full amount to it when confirmed.
        app(PaymentEarmarkRegistry::class)->register(
            AmenityReservationEarmarkHandler::EARMARK_TYPE,
            AmenityReservationEarmarkHandler::class,
        );
    }

    /**
     * Module-owned middleware alias (S4 — per-tenant kill-switch). Registered
     * here instead of bootstrap/app.php so the gate ships with the module
     * that owns it. Mirrors User\UserServiceProvider::registerMiddlewareAliases().
     */
    protected function registerMiddlewareAliases(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('amenityReservationsEnabled', EnsureAmenityReservationsEnabled::class);
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            CancelExpiredReservationsCommand::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);
            // S11: daily expiry + payment-reminder sweep, per tenant (self-gated
            // by the module's own enabled flag inside the command).
            $schedule->command('amenities:cancel-expired-reservations')->dailyAt('02:00');
        });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->nameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->nameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->name, '$PATH_LANG$'), $this->nameLower);
            $this->loadJsonTranslationsFrom(module_path($this->name, '$PATH_LANG$'));
        }
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $relativeConfigPath = config('modules.paths.generator.config.path');
        $configPath = module_path($this->name, $relativeConfigPath);

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relativePath = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $configKey = $this->nameLower.'.'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $relativePath);
                    $key = ($relativePath === 'config.php') ? $this->nameLower : $configKey;

                    $this->publishes([$file->getPathname() => config_path($relativePath)], 'config');
                    $this->mergeConfigFrom($file->getPathname(), $key);
                }
            }
        }
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, '$PATH_VIEWS$');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

        $componentNamespace = $this->module_namespace($this->name, $this->app_path(config('modules.paths.generator.component-class.path')));
        Blade::componentNamespace($componentNamespace, $this->nameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->nameLower)) {
                $paths[] = $path.'/modules/'.$this->nameLower;
            }
        }

        return $paths;
    }
}
